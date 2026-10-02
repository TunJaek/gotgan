{{--
    목록 카드 한 장. 장터 목록(posts/⚡index)과 업체 페이지(companies/⚡show)가 같이 쓴다(2026-09-30).
        <x-post-card :post="$post" :want="$is_want" wire:key="post-{{ $post->idx }}" />
    want    구해요 카드면 true. 가격 대신 예산, 사진이 없으면 사진 자리를 비우지 않고 뺀다
    company 업체명을 보일지. 업체 페이지는 전부 같은 업체라 false
    $post->user 를 쓰므로 목록 쪽에서 ->with('user') 로 미리 불러 둔다(N+1 방지)
--}}
@props(['post', 'want' => false, 'company' => true])

<a {{ $attributes->merge(['class' => 'listing', 'href' => route('posts.detail', $post)]) }}>
    {{-- 대표 사진 = posts.photos 의 첫 번째. 없으면 회색 자리 표시. 구해요는 사진이 있을 때만 --}}
    @if ($post->coverPhoto())
        <img class="photo" src="{{ $post->photoUrl(0) }}"
             alt="{{ $post->coverPhoto()['alt'] ?? $post->title }}"
             loading="lazy" style="object-fit:cover">
    @elseif (! $want)
        <div class="photo">
            {!! App\Support\Icon::render('box') !!}
            <small>사진 미등록</small>
        </div>
    @endif

    <div class="listing-body">
        <div class="listing-title">
            @if ($post->created_at->gt(now()->subDay()))
                <span class="badge-new">새 글</span>
            @endif
            {{ $post->title }}
        </div>

        <div class="price-line">
            @if ($want)
                <span class="price">{{ $post->budget_amount ? '예산 '.number_format($post->budget_amount).'원' : '예산 협의' }}</span>
            @elseif ($post->price_amount)
                <span class="price">{{ number_format($post->price_amount) }}원</span>
            @else
                <span class="price">가격 협의</span>
            @endif

            @if (! $want && $post->price_negotiable)
                <small class="dim">협의 가능</small>
            @endif
        </div>

        {{-- 상태·지역 → 그 아래 업체·시간(보조 정보) --}}
        @if ($post->condition || $post->region_name)
            <div class="listing-meta">
                @if ($post->condition)
                    <span class="badge gray">{{ $post->condition->label() }}</span>
                @endif
                @if ($post->region_name)
                    {{-- 시군구까지. 동은 상세에서 --}}
                    <span>{{ \Illuminate\Support\Str::beforeLast($post->region_name, ' ') }}</span>
                @endif
            </div>
        @endif
        <div class="listing-sub">
            @if ($company)
                <span>{{ $post->user->company_name }}</span>
                <span>·</span>
            @endif
            <span>{{ $post->created_at->diffForHumans() }}</span>
        </div>
    </div>
</a>
