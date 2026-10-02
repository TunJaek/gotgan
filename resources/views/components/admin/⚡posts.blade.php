<?php

/*
 * ┌─ 매물 관리 목록 ────────────────────────────────────────────────
 * │ 오는 길    GET /admin/posts  ·  auth + admin
 * │
 * │ 왜 있나    숨김을 되돌리는 자리다. 신고를 뒤지지 않고도 복구할 수 있어야 한다
 * │            그래서 기본 탭이 '운영자 숨김'이다
 * └────────────────────────────────────────────────────────────────
 */

use App\Concerns\AdminList;
use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Models\Post;
use App\Models\Report;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '매물 관리'])]
class extends Component
{
    use AdminList;

    /** 들어오면 전체가 먼저 보인다. 숨긴 것만 보려면 탭을 누른다. */
    public string $tab = 'all';      // all | hidden

    protected function sortable(): array
    {
        return ['idx', 'title', 'created_at', 'price_amount'];
    }

    public function with(): array
    {
        $query = Post::query()->with('user:idx,company_name');

        if ($this->tab === 'hidden') {
            $query->where('is_hidden', true);
        }

        if ($this->term() !== '') {
            $query->where('title', 'like', '%'.$this->term().'%');
        }

        $posts = $this->applySort($query)->paginate($this->per);

        // 미처리 신고 건수를 한 번에 붙인다. 행마다 세면 페이지당 30번 나간다.
        $counts = Report::query()
            ->where('target_type', ReportTarget::Post)
            ->where('status', ReportStatus::Received)
            ->whereIn('target_idx', $posts->pluck('idx'))
            ->selectRaw('target_idx, COUNT(*) as c')
            ->groupBy('target_idx')
            ->pluck('c', 'target_idx');

        return [
            'posts' => $posts,
            'reportCounts' => $counts,
            'hiddenCount' => Post::where('is_hidden', true)->count(),
            'allCount' => Post::count(),
        ];
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <h1>매물 관리</h1>
            <p class="sub">운영자가 숨긴 매물을 되돌립니다. 판매자가 정한 판매 상태는 건드리지 않습니다.</p>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card-head">
            <button type="button" @class(['adm-btn', 'primary' => $tab === 'all']) wire:click="$set('tab', 'all')">전체 {{ $allCount }}</button>
            <button type="button" @class(['adm-btn', 'primary' => $tab === 'hidden']) wire:click="$set('tab', 'hidden')">운영자 숨김 {{ $hiddenCount }}</button>

            <div class="spacer"></div>

            <div class="adm-filter">
                <label class="sr" for="kw">제목 검색</label>
                <input id="kw" type="search" wire:model.live.debounce.400ms="keyword" placeholder="제목으로 찾기">
                <span class="adm-loading" wire:loading>불러오는 중…</span>
                <x-admin.per-page />
            </div>
        </div>

        <div class="adm-card-body tight">
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                    <tr>
                        <x-admin.th sort="idx" :current="$this->currentSort()">번호</x-admin.th>
                        <x-admin.th sort="title" :current="$this->currentSort()">제목</x-admin.th>
                        <x-admin.th>판매자</x-admin.th>
                        <x-admin.th>노출</x-admin.th>
                        <x-admin.th>판매 상태</x-admin.th>
                        <x-admin.th sort="price_amount" :current="$this->currentSort()">가격</x-admin.th>
                        <x-admin.th>미처리 신고</x-admin.th>
                        <x-admin.th sort="created_at" :current="$this->currentSort()">등록일</x-admin.th>
                        <x-admin.th>관리</x-admin.th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($posts as $post)
                        <tr @class(['off' => $post->is_hidden])>
                            <td>#{{ $post->idx }}</td>
                            <td>{{ $post->title }}</td>
                            <td class="muted">{{ $post->user->company_name ?? '탈퇴 회원' }}</td>
                            <td><span class="adm-badge {{ $post->is_hidden ? 'off' : 'done' }}">{{ $post->is_hidden ? '숨김' : '노출' }}</span></td>
                            <td><span class="adm-badge gray">{{ $post->status->label($post->post_type) }}</span></td>
                            <td class="num">{{ $post->price_amount ? number_format($post->price_amount) : '협의' }}</td>
                            <td class="num">
                                @if ($n = ($reportCounts[$post->idx] ?? 0))
                                    <a class="adm-badge off" href="{{ route('admin.report', ['type' => 1, 'idx' => $post->idx]) }}">{{ $n }}</a>
                                @else
                                    <span class="zero">0</span>
                                @endif
                            </td>
                            <td class="muted">{{ $post->created_at?->timezone('Asia/Seoul')->format('Y-m-d') }}</td>
                            <td><a class="adm-btn tiny" href="{{ route('admin.post', $post) }}">열기</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><div class="adm-empty">해당하는 매물이 없습니다.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <x-admin.pager :rows="$posts" />
        </div>
    </div>
</div>
