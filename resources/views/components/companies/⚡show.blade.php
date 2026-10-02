<?php

/*
 * ┌─ 업체 페이지 ───────────────────────────────────────────────────
 * │ 오는 길    GET /companies/{user}  ·  누구나 (미들웨어 없음)
 * │            매물 상세의 업체명·'이 업체의 글 전체 보기'에서 들어온다
 * │
 * │ 내놀 것    업체 카드(상세에 이미 공개된 정보만) + 이 업체의 팝니다/구해요 글
 * │            글 기준은 장터 목록과 같다: 판매중 · 숨김 아님 · 게시기간 안
 * │            거래완료 글은 넣지 않는다 — 실제 거래 여부를 확인할 수 없는 정보라서(2026-09-30)
 * │
 * │ 막는 것    탈퇴 회원, 한 번도 승인되지 않은 계정은 404
 * └────────────────────────────────────────────────────────────────
 */

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\UserStatus;
use App\Models\Post;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    private const PAGE = 20;

    public User $user;

    #[Url(except: 1)]
    public int $type = 1;                 // PostType::Sell

    public int $limit = self::PAGE;

    public function mount(): void
    {
        abort_if($this->user->status === UserStatus::Withdrawn || ! $this->user->approved_at, 404);
    }

    /** 탭을 바꾸면 처음 20건부터 */
    public function updated($property): void
    {
        if ($property !== 'limit') {
            $this->limit = self::PAGE;
        }
    }

    public function more(): void
    {
        $this->limit += self::PAGE;
    }

    /** 이 업체의 지금 보이는 글. 기준은 장터 목록(posts/⚡index 의 listedQuery)과 같다 */
    private function listed()
    {
        return Post::where('user_idx', $this->user->idx)
            ->where('status', PostStatus::Selling)
            ->where('is_hidden', 0)
            ->stillListed();
    }

    public function with(): array
    {
        $type = PostType::tryFrom($this->type) ?? PostType::Sell;   // 주소에 이상한 값이 오면 팝니다
        $counts = $this->listed()->toBase()->selectRaw('post_type, COUNT(*) AS n')->groupBy('post_type')->pluck('n', 'post_type')->all();

        return [
            'is_want' => $type === PostType::Want,
            'counts' => $counts,
            'total' => $counts[$type->value] ?? 0,
            'posts' => $this->listed()->where('post_type', $type)->with('user')->latest()->take($this->limit)->get(),
        ];
    }
}; ?>

<div>
    {{-- 보통 매물 상세에서 온다. 브라우저 뒤로 가기로 돌아가고, 기록이 없으면(주소로 바로 들어옴) 장터로 --}}
    <a class="back" href="{{ route('market.index') }}"
       x-data x-on:click="if (history.length > 1) { $event.preventDefault(); history.back() }">{!! App\Support\Icon::render('back') !!}돌아가기</a>

    {{-- 업체 카드. 상세 하단 '판매 업체' 카드와 같은 정보만 쓴다 --}}
    <section class="panel">
        <div class="company">
            <div class="avatar" aria-hidden="true">{{ mb_substr($user->company_name, 0, 1) }}</div>
            <div style="flex:1;min-width:0">
                <h1 style="font-size:22px">{{ $user->company_name }}</h1>
                <p class="sub" style="margin-top:4px">
                    {{ $user->regionName() }}
                    · {{ $user->approved_at->format('Y년 n월') }}부터 곳간 이용
                </p>
                {{-- 이름 옆에 두면 휴대폰에서 이름 칸이 좁아져 글자가 세로로 깨진다. 아래 줄에 둔다 --}}
                <span class="badge blue" style="margin-top:8px">사업자 서류 확인 {{ $user->approved_at->format('Y.n.j') }}</span>
            </div>
        </div>
    </section>

    <div class="toolbar" style="margin-top:22px">
        <div class="chips">
            @foreach (PostType::cases() as $case)
                <button type="button" @class(['chip', 'active' => $type === $case->value])
                        wire:click="$set('type', {{ $case->value }})">
                    {{ $case->label() }} {{ number_format($counts[$case->value] ?? 0) }}
                </button>
            @endforeach
        </div>
        <span class="dim" style="font-size:14px">최신순</span>
    </div>

    @if ($posts->isEmpty())
        <div class="empty">
            {!! App\Support\Icon::render('box') !!}
            <p>{{ $is_want ? '지금 올라와 있는 구해요 글이 없습니다.' : '지금 올라와 있는 판매글이 없습니다.' }}</p>
        </div>
    @else
        <div class="listing-set">
            @foreach ($posts as $post)
                <x-post-card :post="$post" :want="$is_want" :company="false" wire:key="post-{{ $post->idx }}" />
            @endforeach
        </div>

        <x-load-more :shown="$posts->count()" :total="$total" />
    @endif
</div>
