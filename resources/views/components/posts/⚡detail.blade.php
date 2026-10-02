<?php

use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    /**
     * 판매자가 직접 고를 수 있는 상태 목록.
     * 화면의 select 와 updatedStatus() 의 검증이 이 하나를 같이 본다.
     * 작성중(Draft) 은 등록 과정이 주는 상태라 뺐다 — 되돌리면 resolveRouteBinding 이
     * 막아서 자기 글이 상세에서 사라진다. 보관(Archived) 은 내 재고 화면에서 다룬다.
     */
    private const CHANGEABLE = [PostStatus::Selling, PostStatus::Done, PostStatus::Stopped];

    /** reports.target_type — 1=매물. 값은 create_ops_tables 마이그레이션 주석에 있다. */
    private const TARGET_POST = 1;

    /**
     * 한 회원이 하루(최근 24시간)에 연락처를 보거나 새로 문의할 수 있는 매물 수.
     * 연락처·업체 목록을 긁어 가는 것을 막는다. 같은 매물을 다시 보는 건 세지 않는다(2026-09-30).
     */
    private const DAILY_CONTACTS = 30;
    private const DAILY_INQUIRIES = 20;

    public Post $post;

    public int $status;

    public function mount(): void{
        $this->status           = $this->post->status->value;
    }

    #[Locked]
    public bool $contact_visible = false;

    // 문의 모달. 첫 메시지를 보내면서 방을 찾거나 만든다(가이드 12-4).
    #[Locked]
    public bool $inquiry_open = false;

    public string $inquiry_message = '';

    #[Locked]
    public bool $report_open = false;

    #[Locked]
    public bool $reported = false;

    public string $report_reason = '';

    public function with(): array
    {

        return [
            'photos'            => $this->post->photos ?? [],   // JSON 컬럼. 첫 번째가 대표
            'category_name'     => $this->post->category->pathName(),
            'region_name'       => $this->post->region_name ?? '지역 미정',   // 자유 입력이라 변환하지 않는다
            'is_favorited'      => auth()->user()?->favoritePosts()->where('posts.idx', $this->post->idx)->exists() ?? false,
            'seller_phone'      => $this->contact_visible ? $this->formattedPhone() : null,
            'is_mine'           => $this->isMine(),
            'status_options'    => self::CHANGEABLE,
            'reported'          => $this->reported || $this->alreadyReported(),
            'seller'            => $this->post->user,
            // 이 업체가 올린 다른 글. 목록에 보이는 것만(판매중·숨김 아님·게시기간 안), 최근 4건
            'seller_posts'      => Post::where('user_idx', $this->post->user_idx)
                ->where('idx', '!=', $this->post->idx)
                ->where('status', PostStatus::Selling)
                ->where('is_hidden', false)
                ->stillListed()
                ->latest()
                ->take(4)
                ->get(),
            'seller_post_count' => Post::where('user_idx', $this->post->user_idx)
                ->where('status', PostStatus::Selling)
                ->where('is_hidden', false)
                ->stillListed()
                ->count(),
        ];
    }

    public function toggleFavorite()
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }
        if ($reason = $this->cannotDeal()) {
            $this->addError('action', $reason);

            return;
        }

        auth()->user()->favoritePosts()->toggle($this->post->idx);
    }

    public function revealContact()
    {
        if (! $this->post->show_phone || ! $this->post->isOpenForDeal()) {
            return;
        }

        if (! auth()->check()) {
            return redirect()->route('login');
        }

        if ($reason = $this->cannotDeal()) {
            $this->addError('action', $reason);

            return;
        }

        if (! $this->isMine()) {
            if ($this->overDailyLimit('contact_access_logs', 'viewer_user_idx', 'accessed_at', self::DAILY_CONTACTS)) {
                $this->addError('action', '오늘 연락처를 본 매물이 '.self::DAILY_CONTACTS.'건이 되어 더 볼 수 없습니다. 내일 다시 이용하시거나 문의하기를 이용해 주세요.');

                return;
            }

            DB::table('contact_access_logs')->insert([
                'viewer_user_idx' => auth()->id(),
                'post_idx' => $this->post->idx,
                'accessed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->contact_visible = true;
    }

    /** 보이던 번호를 다시 숨긴다. 숨기는 것은 열람이 아니라서 기록하지 않는다. 다시 보면 그때 또 기록된다 */
    public function hideContact(): void
    {
        $this->contact_visible = false;
    }

    public function openInquiry(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login');

            return;
        }

        if ($reason = $this->cannotDeal()) {
            $this->addError('action', $reason);

            return;
        }

        if ($this->isMine() || ! $this->post->isOpenForDeal()) {
            return;                          // 자기 글, 거래가 끝난 글에는 문의할 수 없다
        }

        $this->inquiry_open = true;
    }

    public function closeInquiry(): void
    {
        $this->inquiry_open = false;
        $this->inquiry_message = '';
        $this->resetErrorBag();
    }

    public function sendInquiry()
    {
        // 모달이 화면에 없어도 요청은 보낼 수 있다. 로그인·승인을 여기서 다시 본다.
        abort_unless(auth()->check() && auth()->user()->isApproved(), 403);
        abort_if($this->isMine(), 403);      // 자기 글에는 문의할 수 없다
        abort_unless($this->post->isOpenForDeal(), 403);  // 거래완료·중지된 글에는 새 문의를 받지 않는다(이미 시작한 대화는 문의함에서 이어간다)

        $this->validate([
            'inquiry_message' => ['required', 'min:5', 'max:1000'],
        ]);

        if ($error = Message::tooFast(auth()->id())) {
            $this->addError('inquiry_message', $error);

            return;
        }

        // 상세에서 보낸 문의에만 post_idx 가 붙는다(문의함 답장은 null). 그래서 '문의한 매물 수'로 센다
        if ($this->overDailyLimit('messages', 'sender_idx', 'created_at', self::DAILY_INQUIRIES)) {
            $this->addError('inquiry_message', '오늘 문의한 매물이 '.self::DAILY_INQUIRIES.'건이 되어 새 매물에는 내일 문의할 수 있습니다. 이미 대화 중인 곳은 문의함에서 이어갈 수 있습니다.');

            return;
        }

        // 방을 찾는 조건은 Inquiry::between() 한 곳에만 둔다(가이드 12-3).
        $room = Inquiry::between(auth()->user(), $this->post->user);

        // 메시지만 남고 방의 마지막 시각이 안 바뀌면 문의함 정렬이 어긋난다. 둘을 묶는다.
        DB::transaction(function () use ($room) {
            Message::create([
                'inquiry_idx' => $room->idx,
                'sender_idx' => auth()->id(),
                'post_idx' => $this->post->idx,
                // 지금 값을 그대로 박아둔다. 나중에 글이 바뀌어도 대화 맥락이 남는다.
                'terms_snapshot' => Message::snapshotOf($this->post),
                'body' => $this->inquiry_message,
            ]);

            $room->update(['last_message_at' => now()]);
        });

        $this->closeInquiry();

        return $this->redirectRoute('inquiries.show', $room);
    }

    public function openReport(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login');

            return;
        }
        if ($reason = $this->cannotDeal()) {
            $this->addError('report', $reason);   // 신고 링크 옆에 보인다

            return;
        }

        $this->report_open = true;
    }

    public function closeReport(): void
    {
        $this->report_open = false;
        $this->report_reason = '';
        $this->resetErrorBag();
    }

    public function report(): void
    {
        // 모달이 화면에 없어도 요청은 보낼 수 있다. 로그인·승인을 여기서 다시 본다.
        abort_unless(auth()->check() && auth()->user()->isApproved(), 403);
        abort_if($this->isMine(), 403);          // 자기 글은 신고할 수 없다

        $this->validate([
            'report_reason' => ['required', 'min:5', 'max:500'],
        ]);

        // 검토 중이거나 최근 기각된 건이면 접수하지 않는다. 판단은 Report 한 곳에서만 한다.
        if ($blocked = $this->blockReason()) {
            $this->addError('report_reason', $blocked);

            return;
        }

        Report::create([
            'reporter_idx' => auth()->id(),
            'target_type' => ReportTarget::Post,
            'target_idx' => $this->post->idx,
            'reason' => $this->report_reason,
            'status' => ReportStatus::Received,
        ]);

        $this->reported = true;
        $this->closeReport();
    }

    /** 지금 신고할 수 없는 이유. 신고할 수 있으면 null. */
    private function blockReason(): ?string
    {
        return Report::blockReason(auth()->id(), ReportTarget::Post, $this->post->idx);
    }

    private function alreadyReported(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        // 화면에 '신고가 접수되었습니다' 를 띄울지 정하는 값이다.
        // 처리가 끝난 예전 신고까지 세면 다시 신고할 수 있는데도 버튼이 사라진다.
        return Report::where('reporter_idx', auth()->id())
            ->where('target_type', ReportTarget::Post)
            ->where('target_idx', $this->post->idx)
            ->unresolved()
            ->exists();
    }

    public function updatedStatus(): void
    {
        // select 가 화면에 없어도 요청은 보낼 수 있다. 그래서 여기서 다시 확인한다.
        abort_unless($this->isMine(), 403);

        // tryFrom 은 enum 에 없는 값이면 예외 대신 null 을 준다.
        // enum 에 있어도 고를 수 있는 값인지는 CHANGEABLE 로 따로 거른다.
        $new_status = PostStatus::tryFrom($this->status);
        abort_unless(in_array($new_status, self::CHANGEABLE, true), 422);

        $old_status = $this->post->status;

        if ($new_status === $old_status) {
            return;                         // 같은 값을 다시 골랐다. 이력을 남길 게 없다.
        }

        // 글만 바뀌고 이력이 안 남으면 이력의 의미가 없다. 둘을 한 덩어리로 묶는다.
        DB::transaction(function () use ($old_status, $new_status) {
            $this->post->update(['status' => $new_status]);

            DB::table('post_status_logs')->insert([
                'post_idx'   => $this->post->idx,
                'old_status' => $old_status->name,   // 숫자를 넣으면 나중에 로그만 보고 알 수 없다
                'new_status' => $new_status->name,
                'actor_idx'  => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function formattedPhone(): string
    {
        $phone = preg_replace('/\D/', '', $this->post->user->phone);

        return strlen($phone) === 11
            ? substr($phone, 0, 3).'-'.substr($phone, 3, 4).'-'.substr($phone, 7)
            : $phone;
    }
    /**
     * 로그인은 했지만 관심·연락처·문의·신고를 못 쓰는 이유. 쓸 수 있으면 null.
     * 예전에는 내 정보로 보냈는데, 그 화면이 이유를 안 보여 줘서 튕기는 것처럼 보였다(2026-09-30).
     * 그래서 다른 화면으로 보내지 않고 버튼 아래에 이유를 띄운다.
     */
    private function cannotDeal(): ?string
    {
        return auth()->user()->isApproved()
            ? null
            : '사업자 확인을 마친 회원만 이용할 수 있습니다. 심사 현황은 내 정보에서 확인해 주세요.';
    }

    /** 최근 24시간에 이 회원이 다룬 매물 수가 한도에 찼는가. 이 매물을 이미 다뤘으면 다시 하는 것이라 막지 않는다 */
    private function overDailyLimit(string $table, string $user_column, string $time_column, int $limit): bool
    {
        if (auth()->user()->isAdmin()) {
            return false;   // 운영자는 점검하느라 많이 볼 수 있다. 열람 기록은 똑같이 남는다
        }
        $recent = fn () => DB::table($table)->where($user_column, auth()->id())->where($time_column, '>=', now()->subDay());

        return ! $recent()->where('post_idx', $this->post->idx)->exists()
            && $recent()->distinct()->count('post_idx') >= $limit;
    }

    private function isMine(): bool{
        return auth()->id() === $this->post->user_idx;
    }
}; ?>

{{--
 ┌─ 매물 상세 · HTML 만 ────────────────────────────────────────────
 │ 시안 곳간_UI_수정안_v0.3/index.html 의 detail() 을 옮긴 것.
 │ 수량·묶음·최소구매는 컬럼이 없으므로(설명글에 자유 기술) 전부 뺐다.
 │
 │ 이 마크업이 기대하는 값 — with() 에서 넘겨야 한다
 │   $post          Post 1건
 │   $photos        posts.photos 배열. 비어 있어도 된다. 첫 번째가 대표
 │                  각 항목은 ['key' => 저장경로, 'alt' => 대체텍스트]
 │                  사진은 스토리지 URL 이 아니라 라우트로 내보낸다.
 │                  명세서 9장 — 원본 경로에는 웹 라우트를 두지 않는다.
 │                  route('images.show', [$post, $i]) 라우트를 만들어야 한다
 │   $category_name  categories 경로 이름. '원자재 › 금속' 처럼 나온다
 │   $region_name    posts.region_name. 주소 검색으로 고른 읍면동까지의 이름
 │
 │ 아직 없어서 비워둔 곳
 │   · 사진 전달 라우트 GET /images/{post}/{n} (명세서 7장·9장)
 │   · 관심·연락처·문의·신고 버튼의 동작 → wire:click 을 여기 붙인다
 │
 │ 전체 흐름은 docs/가이드.md 10장
 └─────────────────────────────────────────────────────────────────
--}}
@php
    use App\Enums\PostType;
    use App\Support\Icon;

    $isWant = $post->post_type === PostType::Want;
@endphp

<div>
    <a class="back" href="{{ route('market.index') }}">{!! Icon::render('back') !!}장터로 돌아가기</a>
    <div class="detail-grid">
        <div class="detail-gallery" x-data="{
            current: 0,
            urls: @js(collect($photos)->keys()->map(fn ($i) => $post->photoUrl($i)))
        }">
            @if ($photos === [])
                <div class="hero-photo">
                    {!! Icon::render('image') !!}
                    <span>등록된 사진이 없습니다</span>
                </div>
            @else
                <img class="hero-photo" :src="urls[current]"
                     alt="{{ $photos[0]['alt'] ?? $post->title }}" style="object-fit:cover">

                @if (count($photos) > 1)
                    <div class="thumbs">
                        @foreach ($photos as $i => $photo)
                            <img class="photo" src="{{ $post->photoUrl($i) }}"
                                 x-on:click="current = {{ $i }}"
                                 :class="current === {{ $i }} && 'active'"
                                 alt="{{ $photo['alt'] ?? '' }}" style="object-fit:cover">
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        <section class="panel detail-summary">
            <div class="chips">
                <span class="badge">{{ $post->status->label($post->post_type) }}</span>
                @if ($post->condition)
                    <span class="badge gray">{{ $post->condition->label() }}</span>
                @endif
                <span class="badge blue">{{ $post->post_type->label() }}</span>
            </div>

            <h1>{{ $post->title }}</h1>
            <p class="sub">{{ $region_name }} · {{ $post->created_at->diffForHumans() }}</p>

            <div class="detail-price">
                @if ($isWant)
                    <div class="price">
                        {{ $post->budget_amount ? number_format($post->budget_amount).'원' : '예산 협의' }}
                    </div>
                @else
                    <div class="price">
                        {{ $post->price_amount ? number_format($post->price_amount).'원' : '가격 협의' }}
                    </div>
                    <p class="sub">
                        {{ $post->vat_mode?->label() ?? '부가세 협의' }}
                        @if ($post->price_negotiable) · 가격 협의 가능 @endif
                    </p>
                @endif
            </div>

            <dl class="facts">
                <dt>카테고리</dt>
                <dd>{{ $category_name }}</dd>

                @if ($post->condition)
                    <dt>물품 상태</dt>
                    <dd>{{ $post->condition->label() }}</dd>
                @endif

                <dt>{{ $isWant ? '희망 지역' : '물품 지역' }}</dt>
                <dd>{{ $region_name }}</dd>

                @if ($isWant)
                    <dt>게시 마감일</dt>
                    <dd>{{ $post->listed_until?->format('Y-m-d') ?? '미정' }}</dd>
                @endif
            </dl>

            {{-- 여기까지 왔다는 건 글쓴이 본인이라는 뜻이다. 남에게는 404 로 막힌다. --}}
            @if ($post->isExpired())
                <p class="note warning">
                    게시 마감일({{ $post->listed_until->format('Y-m-d') }})이 지나 다른 사람에게는 보이지 않습니다.
                    계속 게시하려면 수정에서 마감일을 미뤄 주세요.
                </p>
            @endif

            <p class="note">수량과 인수 일정은 아래 설명을 확인한 뒤 판매자와 협의해 주세요.</p>

            {{-- 내 글이면 관심·연락처·문의가 의미 없다. 그 자리를 상태 변경으로 바꾼다. --}}
            @if ($is_mine)
                <div class="field">
                    <label for="status">{{ $isWant ? '모집 상태' : '판매 상태' }}</label>
                    {{-- .live 라 고르는 즉시 서버로 간다. 저장 버튼이 따로 없다.
                         화면에서 값을 바꿔 보낼 수 있으므로 소유자 확인·값 검증은
                         updatedStatus() 안에서 다시 한다. --}}
                    <select id="status" wire:model.live="status">
                        @foreach ($status_options as $case)
                            <option value="{{ $case->value }}">{{ $case->label($post->post_type) }}</option>
                        @endforeach
                    </select>
                    <small>고르는 즉시 반영됩니다. 위 상태 표시도 같이 바뀝니다.</small>
                </div>

                <div class="actions">
                    <a class="btn" href="{{ route('posts.edit', $post) }}">수정하기</a>
                </div>
            @else
            {{-- 누구와 거래하는지 문의 버튼 가까이에. 운영자가 실제로 확인한 사실(서류 심사 승인일)만 적는다 --}}
            <p class="seller-line">
                <a href="{{ route('companies.show', $seller) }}"><strong>{{ $seller->company_name }}</strong></a>
                @if ($seller->approved_at)
                    <span class="badge blue">사업자 서류 확인 {{ $seller->approved_at->format('Y.n.j') }}</span>
                @endif
            </p>
            <div class="actions">
                {{-- 관심 매물. 비회원·미승인 처리는 toggleFavorite() 안에서 한다. 모아 보기는 /account/posts?tab=favorites
                     aria-pressed 는 "눌린 채로 유지되는 버튼"이라는 표시라
                     화면 낭독기가 담겼는지 읽어준다. title 은 마우스를 올렸을 때 보이는 이름. --}}
                <button class="btn icon @if ($is_favorited) favorited @endif"
                        wire:click="toggleFavorite"
                        aria-label="{{ $is_favorited ? '관심 해제' : '관심 매물에 담기' }}"
                        title="{{ $is_favorited ? '관심 해제' : '관심 매물에 담기' }}"
                        aria-pressed="{{ $is_favorited ? 'true' : 'false' }}">
                    {!! Icon::render('heart') !!}
                </button>
                @if (! $post->isOpenForDeal())
                    <button class="btn secondary" type="button" disabled>{{ $post->status->label($post->post_type) }}된 글입니다</button>
                @elseif ($post->show_phone)
                    @if ($contact_visible)
                        {{-- 다시 누르면 숨긴다 --}}
                        <button class="btn secondary" wire:click="hideContact" title="다시 누르면 번호를 숨깁니다"
                                aria-label="판매자 연락처 {{ $seller_phone }}. 누르면 숨깁니다">{{ $seller_phone }}</button>
                    @else
                        <button class="btn secondary" wire:click="revealContact">연락처 보기</button>
                    @endif
                @endif
                @if ($post->isOpenForDeal())
                    <button class="btn primary" wire:click="openInquiry">문의하기</button>
                @endif
            </div>
            @error('action')
                <div class="error">{{ $message }}</div>
            @enderror
            {{-- 비공개면 누를 수 없는 버튼 대신 한 줄 안내. 문의하기가 주 행동이 된다 --}}
            @if ($post->isOpenForDeal() && ! $post->show_phone)
                <p class="sub" style="margin-top:10px">연락처 비공개 매물입니다. 문의하기로 연락해 주세요.</p>
            @endif
            @unless ($post->isOpenForDeal())
                <p class="note">{{ $isWant ? '모집이 끝난' : '거래가 끝난' }} 글이라 새 문의와 연락처 보기는 할 수 없습니다. 이미 주고받은 대화는 문의함에서 이어갈 수 있습니다.</p>
            @endunless
            @endif
        </section>
    </div>

    <section class="panel detail-copy">
        <h2>상세 설명</h2>
        {{-- 판매자가 수량·묶음·인수 조건을 여기에 직접 적는다. 줄바꿈만 살리고 HTML 은 막는다. --}}
        <p style="white-space:pre-wrap">{{ $post->description }}</p>
    </section>

    {{-- 누가 파는지(구하는지). 지역은 시군구까지만 보인다(User::regionName). --}}
    <section class="panel" style="margin-top:18px">
        <h2>{{ $isWant ? '구하는 업체' : '판매 업체' }}</h2>
        <div class="company" style="margin-top:14px">
            <div class="avatar" aria-hidden="true">{{ mb_substr($seller->company_name, 0, 1) }}</div>
            <div style="flex:1;min-width:0">
                <a href="{{ route('companies.show', $seller) }}"><strong>{{ $seller->company_name }}</strong></a>
                <p class="sub" style="margin-top:4px">
                    {{ $seller->regionName() }}
                    · {{ ($seller->approved_at ?? $seller->created_at)?->format('Y년 n월') }}부터 곳간 이용
                    · 올린 글 {{ number_format($seller_post_count) }}건
                </p>
            </div>
        </div>

        @if ($seller_posts->isNotEmpty())
            <h3 style="margin:20px 0 10px">이 업체의 다른 글</h3>
            <div class="seller-posts">
                @foreach ($seller_posts as $other)
                    <a href="{{ route('posts.detail', $other) }}">
                        <span class="badge gray">{{ $other->post_type->label() }}</span>
                        <span class="seller-post-title">{{ $other->title }}</span>
                        <span class="dim">
                            @if ($other->post_type === PostType::Want)
                                {{ $other->budget_amount ? '예산 '.number_format($other->budget_amount).'원' : '예산 협의' }}
                            @else
                                {{ $other->price_amount ? number_format($other->price_amount).'원' : '가격 협의' }}
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- 이 업체의 팝니다·구해요 글 전체(업체 페이지) --}}
        <a class="btn full" style="margin-top:14px" href="{{ route('companies.show', $seller) }}">이 업체의 글 전체 보기</a>
    </section>

    @unless ($is_mine)
        <div style="text-align:right">
            @if ($reported)
                <span class="sub">신고가 접수되었습니다.</span>
            @else
                <button class="text-button" wire:click="openReport">이 매물 신고하기</button>
            @endif
            @error('report')
                <div class="error" style="text-align:left">{{ $message }}</div>
            @enderror
        </div>
    @endunless

    {{-- 문의 모달. 첫 메시지를 여기서 받아 방을 만든다.
         방은 사람 한 쌍에 하나라, 이미 대화한 적이 있으면 그 방에 메시지만 더해진다. --}}
    @if ($inquiry_open)
        <dialog x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeInquiry()">
            <div class="dialog-header">
                <h2>문의하기</h2>
            </div>

            <div class="dialog-body">
                <div class="message-product" style="border:1px solid var(--line);border-radius:10px">
                    <strong>{{ $post->title }}</strong>
                    <p class="sub">
                        {{ $post->price_amount ? number_format($post->price_amount).'원' : '가격 협의' }}
                        @if ($post->vat_mode) · {{ $post->vat_mode->label() }} @endif
                    </p>
                </div>

                <div class="field" style="margin-top:18px">
                    <label for="inquiry_message">보낼 내용<span class="required">*</span></label>
                    <textarea id="inquiry_message" wire:model="inquiry_message"
                              placeholder="필요한 수량과 인수 희망 일정을 적어 주세요."></textarea>
                    @error('inquiry_message')
                        <div class="error">{{ $message }}</div>
                    @enderror
                    <small>이 매물의 지금 가격·부가세가 함께 기록됩니다. 나중에 조건이 바뀌어도 대화에 남습니다.</small>
                </div>
            </div>

            <div class="dialog-footer">
                <button type="button" class="btn" wire:click="closeInquiry">취소</button>
                <button type="button" class="btn primary" wire:click="sendInquiry">보내기</button>
            </div>
        </dialog>
    @endif

    {{-- 신고 모달. 브라우저 기본 <dialog> 라 배경을 덮고 포커스를 가둔다.
         ESC·바깥클릭으로 닫히면 $report_open 이 참으로 남으므로 cancel 을 서버에 알린다. --}}
    @if ($report_open)
        <dialog x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeReport()">
            <div class="dialog-header">
                <h2>이 매물 신고하기</h2>
            </div>

            <div class="dialog-body">
                <div class="field">
                    <label for="report_reason">신고 사유<span class="required">*</span></label>
                    <textarea id="report_reason" wire:model="report_reason"
                              placeholder="어떤 점이 문제인지 적어 주세요."></textarea>
                    @error('report_reason')
                        <div class="error">{{ $message }}</div>
                    @enderror
                    <small>운영자가 확인한 뒤 조치합니다. 결과는 따로 안내하지 않습니다.</small>
                </div>
            </div>

            <div class="dialog-footer">
                <button type="button" class="btn" wire:click="closeReport">취소</button>
                <button type="button" class="btn primary" wire:click="report">신고하기</button>
            </div>
        </dialog>
    @endif
</div>
