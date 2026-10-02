{{--
 ┌─ 재고 등록 · HTML 만 ────────────────────────────────────────────
 │ 시안 register() 를 옮긴 것. 바꾼 점 두 가지
 │   · 3단계 마법사 → 한 페이지. 단계 이동은 상태 관리가 따로 필요하다
 │   · 수량·묶음·최소구매 입력 삭제. 컬럼이 없고 설명글에 직접 적는다
 │
 │ 아래 클래스에 만들어야 하는 public 속성 — wire:model 이름과 짝이다
 │   public string $title = '';
 │   public int    $category_id = 0;     새 글은 mount 에서 첫 선택지로
 │   public ?int   $condition = null;      1~4 (PostCondition)
 │   public string $description = '';
 │   public string $price_mode = 'fixed';   fixed | negotiate
 │   public ?int   $price_amount = null;    협의면 null 로 저장
 │   public ?int   $vat_mode = 1;           1~3 (VatMode)
 │   public bool   $price_negotiable = false;
 │   $region_code, $region_name  PicksRegion(주소 검색). 읍면동 8자리와 이름
 │   public array  $photos = [];           WithFileUploads 필요
 │   public ?string $saved_at = null;      자동저장 표시용 문구. 저장할 때마다 갈아끼운다
 │
 │ 저장할 때 잊기 쉬운 것
 │   user_idx      로그인한 사람. 폼에서 받지 않는다
 │   post_type     이 화면은 팝니다(1) 고정. 나중에 변경 불가
 │   status        0=작성중 으로 만들고, 사진까지 저장된 뒤 1=판매중
 │
 │ 검증은 명세서 9장 — 사진 1~10장 필수, JPG·PNG·WebP, 장당 10MB
 └─────────────────────────────────────────────────────────────────
--}}
<?php

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\Category;
use App\Models\ContactConsent;
use App\Models\Post;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use App\Support\PicksRegion;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    /** 설명 최대 글자 수. DB(text)는 약 2만 자까지 받지만 거래 조건을 적기엔 이 정도면 충분하다 */
    private const MAX_DESCRIPTION = 5000;

    /** 금액 최대 12자리(9,999억 원) */
    private const MAX_AMOUNT = 999_999_999_999;
    use PicksRegion { pickRegion as private pickRegionOnly; }

    /** 골랐으면 다른 칸처럼 자동저장한다 */
    public function pickRegion(array $address): void
    {
        $this->pickRegionOnly($address);
        $this->updated('region_code');
    }

    public string $title                = '';

    public int $category_id             = 0;    // 새 글은 mount 에서 첫 선택지로 채운다

    public ?int $condition              = null;

    public string $description          = '';

    public string $price_mode           = 'fixed';

    public ?int $price_amount           = null;

    public ?int $vat_mode               = 1;

    public bool $price_negotiable       = false;

    // 번호 공개는 개인정보 제3자 제공이라 기본은 비공개. 공개하려면 $phone_consent 에 체크해야 한다.
    public bool $show_phone             = false;
    public bool $phone_consent          = false;

    /**
     * 기존 사진과 새로 올린 파일이 섞여 있는 한 줄짜리 목록이다.
     *   기존 사진   ['key' => 저장경로, 'alt' => …, 'src_index' => 원래 자리]
     *   새로 올린 것 TemporaryUploadedFile
     * 순서 바꾸기·삭제는 이 배열의 인덱스만 다루므로 둘을 구분하지 않아도 된다.
     */
    public array $photos                = [];

    /** 파일 선택칸 전용. 고를 때마다 갈아치워지므로 $photos 에 붙이고 바로 비운다. */
    public array $upload                = [];

    #[Locked]
    public PostType $type               = PostType::Sell;

    #[Locked]
    public bool $is_edit                = false;

    public function mount(?Post $post = null): void {
        if ($post) {
            abort_unless($post->user_idx === auth()->id(), 403);

            $this->is_edit              = true;
            $this->post_idx             = $post->idx;
            $this->type                 = $post->post_type;
            $this->fillFrom($post);

            // 원래 자리를 같이 들고 다닌다. 순서를 바꿔도 사진 주소를 만들 수 있어야 한다.
            $this->photos               = collect($post->photos ?? [])
                ->map(fn ($photo, $i) => $photo + ['src_index' => $i])
                ->all();

            return;
        }

        $request_type                   = request()->query('type', 'sell');
        $this->type                     = ($request_type === 'want') ? PostType::Want : PostType::Sell;

        // 선택 상자의 첫 칸과 맞춘다. 번호를 박아 두면 그 카테고리를 끌 때 기본값이 '고를 수 없는 값'이 된다(2026-10-02).
        $this->category_id              = array_key_first(Category::selectable()) ?? 0;

        // 새 글은 가입한 사업장 주소(동까지)를 기본으로. 물건이 다른 곳에 있으면 '다시 검색'으로 바꾼다.
        // 예전 가입자(시군구까지만)는 채우지 않고 화면에서 안내한다. 작성 중인 글을 이어 쓰면 그 글의 주소로 덮인다.
        $user = auth()->user();
        if (strlen((string) $user->region_code) === 8) {
            $this->region_code          = $user->region_code;
            $this->region_name          = (string) $user->region_name;
        }
    }

    /** 고른 파일을 갈아치우지 않고 뒤에 붙인다. */
    public function updatedUpload(): void
    {
        $this->validate([
            'upload.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $this->photos                   = array_slice(array_merge($this->photos, $this->upload), 0, 10);
        $this->upload                   = [];
    }

    public ?int $post_idx               = null;

    public bool $ignore_draft           = false;

    public ?string $saved_at            = null;

    public ?string $listed_until             = null;

    public ?int $budget_amount          = null;

    public function with(): array{
        return ['draft' => $this->draftQuery()];
    }

    /** 썸네일 x 버튼. 아직 저장 전이라 배열에서 빼면 끝난다. */
    public function removePhoto(int $i): void{
        unset($this->photos[$i]);
        $this->photos                   = array_values($this->photos);   // 색인을 0부터 다시 매긴다
    }

    /** 드래그·화살표로 순서 변경. $from 자리의 사진을 $to 자리로 옮긴다. */
    public function movePhoto(int $from, int $to): void{
        $last = count($this->photos) - 1;
        if ($from === $to || $from < 0 || $to < 0 || $from > $last || $to > $last) {
            return;                                    // 범위를 벗어나면 아무것도 안 한다
        }

        $moved      = array_splice($this->photos, $from, 1); // 뺀 다음
        array_splice($this->photos, $to, 0, $moved);    // 그 자리에 끼운다
    }

    private function draftQuery(): ?Post{
        if ($this->is_edit || $this->post_idx != null || $this->ignore_draft) {
            return null;
        }

        return Post::where('user_idx', auth()->id())
            ->where('status', PostStatus::Draft)
            ->where('post_type', $this->type)
            ->latest('updated_at')
            ->first();
    }

    public function resumeDraft(){
        $post = $this->draftQuery();
        if (!$post) {
            return;
        }

        $this->post_idx         = $post->idx;
        $this->fillFrom($post);
    }

    /** 글 하나를 폼에 펼친다. 사진은 부르는 쪽에서 따로 채운다. */
    private function fillFrom(Post $post): void
    {
        $this->title            = $post->title;
        $this->category_id      = $post->category_id;
        $this->description      = $post->description;
        $this->region_code      = (string) $post->region_code;
        $this->region_name      = (string) $post->region_name;
        $this->price_amount     = $post->price_amount;
        $this->price_negotiable = (bool) $post->price_negotiable;
        $this->show_phone       = (bool) $post->show_phone;
        $this->phone_consent    = (bool) $post->show_phone;   // DB 에 공개로 남은 글은 동의를 거친 글뿐이다
        $this->condition        = $post->condition?->value;
        $this->vat_mode         = $post->vat_mode?->value;
        $this->budget_amount    = $post->budget_amount;
        $this->listed_until        = $post->listed_until?->format('Y-m-d');
        $this->price_mode       = $post->price_amount === null ? 'negotiate' : 'fixed';
    }

    /** 검증 실패 모달의 '확인'. resetErrorBag() 은 화면에서 직접 못 부르므로 여기로 통과시킨다. */
    public function dismissErrors(): void{
        $this->resetErrorBag();
    }

    public function discardDraft(){
        $this->draftQuery()?->delete();
        $this->ignore_draft = true;
    }

    public function saveDraft(){
        $this->validate(['title' => ['required', 'min:5']]);
    }

    private function persist(PostStatus $status): Post
    {
        $photos = [];
        foreach ($this->photos as $photo) {
            // 기존 사진은 배열, 새로 올린 것은 업로드 객체다.
            $photos[] = is_array($photo)
                ? ['key' => $photo['key'], 'alt' => $photo['alt'] ?? null]
                : ['key' => $photo->store('posts', 'local'), 'alt' => null];
        }
        $sell_data              = [
            'condition'         => $this->condition,
            'price_amount'      => $this->price_mode === 'fixed' ? $this->price_amount : null,
            'vat_mode'          => $this->vat_mode,
            'price_negotiable'  => $this->price_negotiable,
        ];
        $want_data              = [
            'budget_amount'     => $this->budget_amount,
            'listed_until'         => $this->listed_until,
        ];
        $common_data            = [
            'title'             => $this->title,
            'photos'            => $status === PostStatus::Draft ? [] : $photos,
            'category_id'       => $this->category_id,
            'description'       => $this->description,
            'show_phone'        => $this->show_phone && $this->phone_consent,
            'region_code'       => $this->region_code ?: null,   // 임시저장 글은 지역을 아직 안 골랐을 수 있다
            'region_name'       => $this->region_name ?: null,
            'user_idx'          => auth()->id(),
            'status'            => $status,
            'is_hidden'         => false,
        ];
        $data                   = array_merge($common_data, ($this->type === PostType::Want)? $want_data : $sell_data);
        if ($status === PostStatus::Draft && !$this->post_idx) {
            $this->post_idx     = Post::where('user_idx', $data['user_idx'])
                ->where('status', PostStatus::Draft)
                ->where('post_type', $this->type)
                ->latest('idx')
                ->value('idx');
        }
        if ($this->post_idx) {
            $post               = Post::findOrFail($this->post_idx);
            abort_unless($post->user_idx === auth()->id(), 403);

            if ($this->is_edit) {
                // 상태는 상세 화면에서 바꾼다. 숨김은 운영자가 건다. 여기서 되돌리면 안 된다.
                unset($data['status'], $data['is_hidden']);

                // 목록에서 빠진 사진은 파일도 같이 지운다.
                $removed = array_diff(
                    array_column($post->photos ?? [], 'key'),
                    array_column($photos, 'key')
                );
                if ($removed !== []) {
                    Storage::disk('local')->delete($removed);
                }
            }

            $post->update($data);

            return $post;
        } else {
            $data['post_type']  = $this->type;
            return Post::create($data);
        }
    }

    public function updated($property): void
    {
        if ($this->is_edit) {
            return;
        }
        if ($property === 'photos' || $property === 'upload') {
            return;
        }
        if (trim($this->title) === '') {
            return;
        }
        // 자동저장은 검증을 거치지 않는다. DB 가 못 받는 길이·금액은 저장하지 않고 알린다(그대로 쓰면 500).
        if (mb_strlen($this->description) > self::MAX_DESCRIPTION) {
            $this->addError('description', '설명은 '.number_format(self::MAX_DESCRIPTION).'자까지 쓸 수 있습니다.');

            return;
        }
        foreach (['price_amount', 'budget_amount'] as $field) {
            if ($this->{$field} !== null && ($this->{$field} < 1 || $this->{$field} > self::MAX_AMOUNT)) {
                $this->addError($field, '금액을 다시 확인해 주세요.');

                return;
            }
        }
        // 고를 수 없는 카테고리(꺼진 것·없는 번호)는 FK 에 막히므로 저장하지 않고 알린다.
        if (! array_key_exists($this->category_id, Category::selectable())) {
            $this->addError('category_id', '카테고리를 다시 선택해 주세요.');

            return;
        }

        $this->post_idx = $this->persist(PostStatus::Draft)->idx;
        $this->saved_at = now()->format('H:i');
    }

    public function save()
    {
        $sell_rules             = [
            'condition'         => ['required'],
            'price_mode'        => ['required'],
            'price_amount'      => ['required_if:price_mode,fixed', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'vat_mode'          => ['required'],
            'price_negotiable'  => ['boolean'],
        ];
        $want_rules             = [
            'budget_amount'     => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'listed_until'         => ['nullable','date', 'after_or_equal:today']
        ];
        $common_rules           = [
            'title'             => ['required', 'min:5', 'max:100'],
            'photos'            => ['required', 'array', 'min:1', 'max:10'],
            // 꺼진 카테고리(식자재 등)와 하위 분류가 있는 상위 분류는 고를 수 없다
            'category_id'       => ['required', 'in:'.implode(',', array_keys(Category::selectable()))],
            'description'       => ['required', 'max:'.self::MAX_DESCRIPTION],
            'show_phone'        => ['boolean'],
        ] + $this->regionRules();
        $rules                  = array_merge($common_rules, ($this->type === PostType::Want)? $want_rules : $sell_rules);

        // 공개를 골랐으면 동의 체크가 있어야 한다
        $rules['phone_consent'] = $this->show_phone ? ['accepted'] : [];

        $this->validate($rules, $this->regionMessages() + [
            'phone_consent.accepted' => '휴대폰 번호를 공개하려면 연락처 공개 동의에 체크해 주세요.',
        ]);
        $post                   = $this->persist(PostStatus::Selling);
        ContactConsent::syncFor($post);

        return redirect()->route('posts.detail', $post);

    }

};

?>
@php
    // 아래에서 여러 번 쓰므로 한 번만 계산한다. $this->type 은 #[Locked] 라 화면에서 바뀌지 않는다.
    $is_want = $this->type === PostType::Want;
@endphp
<div class="narrow">
    <div class="page-head">
        <div>
            <h1>{{ $is_edit ? '글 수정' : ($is_want ? '구해요 등록' : '재고 등록') }}</h1>
            <p class="sub">
                {{ $is_want
                    ? '필요한 수량과 일정은 설명에 적어 주세요.'
                    : '남은 수량과 인수 조건은 설명에 적어 주세요.' }}
            </p>
        </div>

        {{-- 자동저장 표시. $saved_at 이 비어 있으면(아직 한 번도 저장 안 됨) 아무것도 안 나온다 --}}
        @if (! empty($saved_at))
            <span class="autosave" aria-live="polite">
                자동저장됨 · {{ $saved_at }}
        </span>
        @endif
    </div>

    {{-- 작성 중인 글이 있을 때만 나온다. $draft 가 없으면(아직 구현 전) 조용히 건너뛴다.
         브라우저 기본 <dialog> 다. x-init 의 showModal() 이 배경을 덮고 포커스를 가둔다.
         @cancel.prevent 는 ESC 를 막는다 — 두 버튼 중 하나는 반드시 고르게 한다.
         둘 중 뭘 누르든 $draft 가 없어져서 이 블록째로 사라진다. 닫는 코드가 따로 없다. --}}
    @if (! empty($draft))
        <dialog x-data x-init="$el.showModal()" x-on:cancel.prevent>
            <div class="dialog-header">
                <h2>작성 중인 글이 있습니다</h2>
            </div>

            <div class="dialog-body">
                <strong>{{ $draft->title !== '' ? $draft->title : '제목 없음' }}</strong>
                <p class="sub">
                    {{ $draft->updated_at->diffForHumans() }}에 저장됨 · 사진은 다시 골라 주세요
                </p>
            </div>

            <div class="dialog-footer">
                <button type="button" class="btn" wire:click="discardDraft">새로 쓰기</button>
                <button type="button" class="btn primary" wire:click="resumeDraft">이어쓰기</button>
            </div>
        </dialog>
    @endif

    {{-- 검증 실패 알림. 등록하기를 누른 뒤 $errors 에 뭔가 담기면 뜬다.
         확인을 누르면 dismissErrors() 가 $errors 를 비워서 이 블록째로 사라진다.
         (임시저장 모달과 같은 방식 — 닫는 코드가 따로 없다) --}}
    @if ($errors->any())
        <dialog x-data x-init="$el.showModal()" x-on:cancel.prevent>
            <div class="dialog-header">
                <h2>입력을 확인해 주세요</h2>
            </div>

            <div class="dialog-body">
                <ul class="error-list">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>

            <div class="dialog-footer">
                <button type="button" class="btn primary" wire:click="dismissErrors">확인</button>
            </div>
        </dialog>
    @endif

    <form class="form-shell" wire:submit="save">
        <div class="form-inner">

            <div class="field">
                <label for="photos">사진<span class="required">*</span></label>

                <div class="upload-row" x-data="{ from: null }">
                    <label class="upload-tile">
                        {!! App\Support\Icon::render('image') !!}
                        <span wire:loading.remove wire:target="upload">{{ count($photos) }}/10</span>
                        <span wire:loading wire:target="upload">올리는 중</span>
                        {{-- $photos 가 아니라 $upload 에 받는다. 여기 직접 물리면 고를 때마다 기존 사진이 날아간다. --}}
                        <input type="file" id="photos" wire:model="upload" multiple
                               accept="image/jpeg,image/png,image/webp">
                    </label>

                    @foreach ($photos as $i => $photo)
                        <div class="thumb" draggable="true"
                             x-on:dragstart="from = {{ $i }}"
                             x-on:dragover.prevent
                             x-on:drop.prevent="$wire.movePhoto(from, {{ $i }})">

                            {{-- 기존 사진은 저장된 주소로, 새로 올린 것은 임시 주소로 --}}
                            <img src="{{ is_array($photo)
                                    ? route('images.show', [$post_idx, $photo['src_index']])
                                    : $photo->temporaryUrl() }}"
                                 alt="선택한 사진 {{ $i + 1 }}">

                            @if ($i === 0)
                                <span class="badge blue thumb-cover">대표</span>
                            @endif

                            <button type="button" class="thumb-remove" title="삭제"
                                    wire:click="removePhoto({{ $i }})"
                                    aria-label="{{ $i + 1 }}번째 사진 삭제">&times;
                            </button>
                        </div>
                    @endforeach
                </div>

                <small>사진 1~10장 · JPG, PNG, WebP · 장당 10MB · 첫 장이 대표 사진입니다.
                    사진은 자동저장되지 않습니다.</small>

            </div>

            <div class="field">
                <label for="title">제목<span class="required">*</span></label>
                <input type="text" id="title" wire:model.live.debounce.150ms="title" maxlength="100">
            </div>

            <div class="field">
                <label for="category">카테고리<span class="required">*</span></label>
                <select id="category" wire:model.live="category_id">
                    @foreach (Category::selectable() as $idx => $path)
                        <option value="{{ $idx }}">{{ $path }}</option>
                    @endforeach
                </select>
            </div>

            @if ($this->type == PostType::Sell)
                <fieldset class="field">
                    <legend>물품 상태</legend>
                    <div class="radio-grid">
                        @foreach (App\Enums\PostCondition::cases() as $case)
                            <label class="radio-option">
                                <input type="radio" wire:model.live="condition" value="{{ $case->value }}">
                                {{ $case->label() }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endif

            <div class="field">
                <label for="description">{{ $is_want ? '필요한 물품 설명' : '물품 설명' }}<span class="required">*</span></label>
                <textarea id="description" wire:model.live.debounce.750ms="description" maxlength="5000"></textarea>
                <small>
                    {{ $is_want
                        ? '필요한 수량, 규격, 원하는 상태, 인수 가능한 일정을 적어 주세요.'
                        : '남은 수량, 묶음 단위, 최소 구매 조건, 보관 상태, 하자를 적어 주세요.' }}
                </small>
            </div>
            <div class="divider"></div>

            @if ($is_want)
                {{-- 예산·희망 수령일 둘 다 선택 입력이다. 비우면 상세에서 '협의' 로 보인다. --}}
                <div class="field">
                    <label for="budget">예산</label>
                    <div class="input-suffix">
                        {{-- 판매 가격 칸과 같은 방식. 쉼표가 섞이면 서버의 ?int 변환이 실패해
                             화면엔 50,000 인데 실제로는 이전 값이 저장되는 사고가 난다. --}}
                        <input type="text" id="budget" inputmode="numeric" autocomplete="off" maxlength="12"
                               wire:model.live.debounce.750ms="budget_amount"
                               x-data
                               x-on:keypress="if (! /[0-9]/.test($event.key)) $event.preventDefault()"
                               x-on:input.capture="$el.value = $el.value.replace(/[^0-9]/g, '')"
                               maxlength="12">
                        <span>원</span>
                    </div>
                    <small>비워 두면 "예산 협의" 로 보입니다.</small>
                </div>

                <div class="field">
                    <label for="listed_until">게시 마감일</label>
                    {{-- 브라우저 기본 날짜 선택기. min 으로 과거를 아예 못 고르게 막지만,
                         브라우저를 안 거치고 보낼 수도 있어 서버의 after_or_equal 검증이 따로 있다. --}}
                    <input type="date" id="listed_until" wire:model.live="listed_until"
                           min="{{ now()->format('Y-m-d') }}">
                    <small>이 날짜까지만 게시합니다. 비워 두면 직접 내릴 때까지 계속 게시됩니다.</small>
                </div>
            @endif

            @if ($this->type == PostType::Sell)
                <fieldset class="field">
                    <legend>가격 표시</legend>
                    <div class="radio-grid">
                        <label class="radio-option">
                            <input type="radio" wire:model.live="price_mode" value="fixed">금액 공개
                        </label>
                        <label class="radio-option">
                            <input type="radio" wire:model.live="price_mode" value="negotiate">가격 협의
                        </label>
                    </div>
                </fieldset>

                {{-- .live 로 묶여 있어서 위를 고르는 즉시 이 블록이 나타나고 사라진다 --}}
                @if ($price_mode === 'fixed')
                    <div class="field">
                        <label for="price">판매 가격<span class="required">*</span></label>
                        <div class="input-suffix">
                            {{-- 숫자만 받는다. 쉼표가 섞이면 서버의 ?int 변환이 실패해서
                                 화면엔 50,000 인데 실제로는 이전 값이 저장되는 사고가 난다.
                                 keypress 로 애초에 못 치게 막고, 붙여넣기는 input 에서 걸러낸다.
                                 min·step 은 type=number 전용이라 여기선 효과가 없어 뺐다. --}}
                            <input type="text" id="price" inputmode="numeric" autocomplete="off" maxlength="12"
                                   wire:model.live.debounce.750ms="price_amount"
                                   x-data
                                   x-on:keypress="if (! /[0-9]/.test($event.key)) $event.preventDefault()"
                                   x-on:input.capture="$el.value = $el.value.replace(/[^0-9]/g, '')"
                                   maxlength="12">
                            <span>원</span>
                        </div>
                    </div>

                    <div class="field">
                        <label for="vat">부가세 표시</label>
                        <select id="vat" wire:model.live="vat_mode">
                            @foreach (App\Enums\VatMode::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="check">
                        <input type="checkbox" wire:model.live="price_negotiable">
                        금액 옆에 "협의 가능" 을 표시합니다
                    </label>
                @endif
            @endif
            <div class="field">
                <label>물품 소재 지역<span class="required">*</span></label>
                @php $has_home = strlen((string) auth()->user()->region_code) === 8; @endphp
                <x-address-search :name="$region_name" :hint="'물건을 실제로 가져가는 곳의 주소를 찾아 주세요. 읍·면·동까지만 저장·공개하고, 정확한 주소는 문의에서 협의합니다.'
                    .(! $is_edit && $has_home ? ' 처음에는 사업장 주소가 채워져 있습니다.' : '')" />
                @if (! $is_edit && ! $has_home)
                    <small>내 정보 → 정보 수정에서 사업장 주소를 다시 고르면, 다음부터 그 주소가 기본으로 채워집니다.</small>
                @endif
                @error('region_code') <p class="error">{{ $message }}</p> @enderror
            </div>

            <fieldset class="field">
                <legend>휴대폰 번호 노출</legend>
                <div class="radio-grid">
                    <label class="radio-option">
                        <input type="radio" wire:model.live="show_phone" value="1">공개
                    </label>
                    <label class="radio-option">
                        <input type="radio" wire:model.live="show_phone" value="0">비공개
                    </label>
                </div>
                <small>공개해도 사업자 확인을 마친 회원만 번호를 볼 수 있습니다. 비공개여도 1:1 문의로 연락받을 수 있습니다.</small>

                {{-- 문구를 고치면 ContactConsent::VERSION 을 올린다 --}}
                @if ($show_phone)
                    <div class="field">
                        <strong>[선택] 매물 연락처 공개를 위한 개인정보 제3자 제공 동의</strong>
                        <ul>
                            <li><small>제공받는 자: 곳간의 승인 회원 중 이 매물에서 '연락처 보기'를 실행한 회원</small></li>
                            <li><small>제공 목적: 이 매물의 영업용 재고 거래 가능 여부, 가격, 수량, 인도·배송 조건 등의 문의와 거래 협의</small></li>
                            <li><small>제공 항목: 곳간 가입 시 인증한 휴대전화번호</small></li>
                            <li><small>보유·이용기간: 이 매물에 관한 거래 상담이 끝날 때까지. 매물이 거래완료·판매중지·삭제된 날부터 30일을 넘겨 보유하거나 이용할 수 없습니다.</small></li>
                            <li><small>동의하지 않아도 매물 등록과 1:1 문의는 이용할 수 있으며, 이 매물에서 휴대전화번호만 공개되지 않습니다.</small></li>
                            <li><small>나중에 비공개로 바꾸면 그때부터 새로 열람할 수 없습니다. 이미 번호를 본 회원에게서 번호를 회수할 수는 없으며, 그 회원에게는 거래 목적 외 이용 금지와 삭제 의무가 계속 적용됩니다.</small></li>
                        </ul>
                        <label class="check-line">
                            <input type="checkbox" wire:model.live="phone_consent">
                            <span>위 내용을 확인했으며, 이 매물의 연락처 공개에 동의합니다.</span>
                        </label>
                        @error('phone_consent') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif
            </fieldset>

            <section class="preview">
                <div class="preview-label">구매자에게 보이는 모습</div>
                <div class="preview-content">
                    <p>{{ $title !== '' ? $title : '제목이 여기 보입니다' }}</p>
                    <strong class="price">
                        @if ($is_want)
                            {{ $budget_amount ? number_format($budget_amount).'원' : '예산 협의' }}
                        @else
                            {{ $price_mode === 'negotiate' || ! $price_amount
                                ? '가격 협의'
                                : number_format($price_amount).'원' }}
                        @endif
                    </strong>
                </div>
            </section>
        </div>

        <div class="form-footer">
            <a class="btn" href="{{ route('market.index') }}">취소</a>
            <button class="btn primary" type="submit">{{ $is_edit ? '수정하기' : '등록하기' }}</button>
        </div>
    </form>
</div>
