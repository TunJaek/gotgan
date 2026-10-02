<?php

/*
 * ┌─ 내 매물 · 관심 매물 ───────────────────────────────────────────
 * │ 오는 길    GET /account/posts            내 매물(모든 상태)
 * │            GET /account/posts?tab=favorites  관심 표시한 글
 * │            내 정보(/account) 의 링크에서 들어온다
 * │
 * │ 내 매물    거래완료·중지·마감·운영자 숨김 글은 목록에 안 나와서 여기서만 찾을 수 있다
 * │            상태 변경은 상세 화면에서 한다(이력 남기기 로직을 한 곳에 둔다)
 * │            작성중 글은 등록 화면의 '작성 중인 글' 창으로 이어 쓴다(글 종류마다 최신 1건)
 * │
 * │ 관심 매물  숨겨졌거나 마감된 글은 상세가 404 라서 링크 없이 '볼 수 없는 글'로 보이고 해제만 할 수 있다
 * └────────────────────────────────────────────────────────────────
 */

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\Post;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    private const PAGE = 20;

    /** 상태 칩 → 해당 상태들 */
    private const FILTERS = [
        'selling' => [PostStatus::Selling],
        'done' => [PostStatus::Done],
        'stopped' => [PostStatus::Stopped, PostStatus::Archived],
        'draft' => [PostStatus::Draft],
    ];

    #[Url(except: 'mine')]
    public string $tab = 'mine';          // mine | favorites

    #[Url(except: '')]
    public string $status = '';           // '' 이면 전체. FILTERS 의 키

    public int $limit = self::PAGE;

    public function updated($property): void
    {
        if ($property === 'tab') {
            $this->status = '';
        }
        if ($property !== 'limit') {
            $this->limit = self::PAGE;
        }
    }

    public function more(): void
    {
        $this->limit += self::PAGE;
    }

    /** 관심 해제. 내 관심 목록에서만 빠진다 */
    public function unfavorite(int $post_idx): void
    {
        auth()->user()->favoritePosts()->detach($post_idx);
    }

    public function with(): array
    {
        if ($this->tab === 'favorites') {
            $query = auth()->user()->favoritePosts()->with('user')
                ->where('posts.status', '!=', PostStatus::Draft)
                ->orderByDesc('favorites.created_at');
        } else {
            $query = Post::where('user_idx', auth()->id())
                ->when(self::FILTERS[$this->status] ?? null, fn ($q, $statuses) => $q->whereIn('status', $statuses))
                ->orderByDesc('updated_at');
        }

        $total = (clone $query)->count();

        return [
            'posts' => $query->take($this->limit)->get(),
            'total' => $total,
        ];
    }
}; ?>

<div class="narrow">
    <a class="back" href="{{ route('account.index') }}">{!! App\Support\Icon::render('back') !!}내 정보</a>

    <div class="page-head">
        <div>
            <h1>{{ $tab === 'favorites' ? '관심 매물' : '내 매물' }}</h1>
            {{-- 두 탭의 설명을 한 줄 길이로 맞춘다. 줄 수가 다르면 탭을 오갈 때 아래 버튼이 위아래로 움직인다(2026-09-30) --}}
            <p class="sub">
                {{ $tab === 'favorites'
                    ? '관심 표시한 글을 모아 봅니다.'
                    : '올린 글을 상태와 관계없이 모두 봅니다.' }}
            </p>
        </div>
    </div>

    <div class="toolbar">
        <div class="chips">
            <button type="button" @class(['chip', 'active' => $tab === 'mine']) wire:click="$set('tab', 'mine')">내 매물</button>
            <button type="button" @class(['chip', 'active' => $tab === 'favorites']) wire:click="$set('tab', 'favorites')">관심 매물</button>
        </div>
        <span class="dim" style="font-size:14px">{{ number_format($total) }}건</span>
    </div>

    @if ($tab === 'mine')
        <div class="chips" style="margin-bottom:16px">
            @foreach (['' => '전체', 'selling' => '판매·모집중', 'done' => '거래완료', 'stopped' => '중지·보관', 'draft' => '작성중'] as $key => $label)
                <button type="button" @class(['chip', 'active' => $status === $key]) wire:click="$set('status', '{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>
    @endif

    @forelse ($posts as $post)
        @php
            $is_want = $post->post_type === PostType::Want;
            $is_draft = $post->status === PostStatus::Draft;
            $is_mine = $post->user_idx === auth()->id();
            // 남에게 안 보이는 이유. 관심 매물에서는 링크를 막는 이유이기도 하다(상세가 404)
            $gone = $post->is_hidden ? 'hidden' : ($post->isExpired() ? 'expired' : null);
        @endphp
        <div class="stock-row" wire:key="post-{{ $post->idx }}">
            <div class="stock-top">
                @if ($post->coverPhoto() && ! $gone)
                    <img class="photo" src="{{ $post->photoUrl(0) }}" alt="{{ $post->coverPhoto()['alt'] ?? $post->title }}"
                         loading="lazy" style="object-fit:cover">
                @else
                    <div class="photo">{!! App\Support\Icon::render('box') !!}</div>
                @endif

                <div class="listing-body">
                    <div class="listing-meta" style="margin:0 0 6px">
                        <span class="badge gray">{{ $post->post_type->label() }}</span>
                        <span @class(['badge', 'blue' => $post->status === PostStatus::Selling, 'yellow' => $is_draft])>
                            {{ $post->status->label($post->post_type) }}
                        </span>
                    </div>
                    <div class="listing-title">{{ $is_draft && $post->title === '' ? '제목 없음' : $post->title }}</div>
                    <div class="price-line">
                        @if ($is_want)
                            <span class="price">{{ $post->budget_amount ? '예산 '.number_format($post->budget_amount).'원' : '예산 협의' }}</span>
                        @else
                            <span class="price">{{ $post->price_amount ? number_format($post->price_amount).'원' : '가격 협의' }}</span>
                        @endif
                    </div>
                    <div class="listing-meta">
                        @if (! $is_mine)
                            <span>{{ $post->user->company_name }}</span>
                            <span>·</span>
                        @endif
                        @if ($post->region_name)
                            <span>{{ Str::beforeLast($post->region_name, ' ') }}</span>
                            <span>·</span>
                        @endif
                        <span>{{ $is_draft ? $post->updated_at->diffForHumans().' 저장' : $post->created_at->format('Y-m-d').' 등록' }}</span>
                    </div>
                </div>
            </div>

            @if ($gone === 'hidden')
                <div class="reconfirm">
                    <p>{{ $is_mine
                        ? '운영자가 숨김 처리한 글입니다. 다른 사람에게 보이지 않습니다. 이유가 궁금하면 고객센터로 문의해 주세요.'
                        : '더 이상 볼 수 없는 글입니다.' }}</p>
                </div>
            @elseif ($gone === 'expired')
                <div class="reconfirm">
                    <p>{{ $is_mine
                        ? '게시 마감일('.$post->listed_until->format('Y-m-d').')이 지나 다른 사람에게 보이지 않습니다. 수정에서 마감일을 늘릴 수 있습니다.'
                        : '게시 기간이 끝난 글입니다.' }}</p>
                </div>
            @endif

            <div class="stock-buttons">
                @if ($tab === 'favorites')
                    @unless ($gone)
                        <a class="btn" href="{{ route('posts.detail', $post) }}">보기</a>
                    @endunless
                    <button type="button" class="btn" wire:click="unfavorite({{ $post->idx }})">관심 해제</button>
                @elseif ($is_draft)
                    <a class="btn primary" href="{{ route('posts.create') }}{{ $is_want ? '?type=want' : '' }}">이어 쓰기</a>
                @elseif ($gone !== 'hidden')
                    <a class="btn" href="{{ route('posts.detail', $post) }}">보기</a>
                    <a class="btn" href="{{ route('posts.edit', $post) }}">수정</a>
                @endif
            </div>
        </div>
    @empty
        <div class="empty">
            {!! App\Support\Icon::render('box') !!}
            <p>{{ $tab === 'favorites' ? '관심 표시한 글이 없습니다.' : '해당하는 글이 없습니다.' }}</p>
            <div class="actions">
                @if ($tab === 'favorites')
                    <a class="btn primary" href="{{ route('market.index') }}">장터 둘러보기</a>
                @else
                    <a class="btn primary" href="{{ route('posts.create') }}">재고 올리기</a>
                @endif
            </div>
        </div>
    @endforelse

    <x-load-more :shown="$posts->count()" :total="$total" />
</div>
