<?php

/*
 * ┌─ 회원 관리 목록 ────────────────────────────────────────────────
 * │ 오는 길    GET /admin/users  ·  auth + admin
 * │
 * │ 왜 있나    정지를 되돌리는 자리다. 기본 탭이 '이용정지'인 이유도 같다
 * │            가입 심사(applications)와는 다른 화면이다 — 저기는 승인, 여기는 제재
 * └────────────────────────────────────────────────────────────────
 */

use App\Concerns\AdminList;
use App\Enums\UserStatus;
use App\Models\Post;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '회원 관리'])]
class extends Component
{
    use AdminList;

    /** 들어오면 전체가 먼저 보인다. 정지 회원만 보려면 탭을 누른다. */
    public string $tab = 'all';   // all | suspended

    protected function sortable(): array
    {
        return ['idx', 'company_name', 'created_at'];
    }

    public function with(): array
    {
        $query = User::query();

        if ($this->tab === 'suspended') {
            $query->where('status', UserStatus::Suspended);
        }

        if ($this->term() !== '') {
            $query->where('company_name', 'like', '%'.$this->term().'%');
        }

        $users = $this->applySort($query)->paginate($this->per);

        // 회원별 매물 수를 한 번에. 행마다 세지 않는다.
        $posts = Post::whereIn('user_idx', $users->pluck('idx'))
            ->selectRaw('user_idx, COUNT(*) as c, SUM(is_hidden) as h')
            ->groupBy('user_idx')
            ->get()->keyBy('user_idx');

        return [
            'users' => $users,
            'postStats' => $posts,
            'suspendedCount' => User::where('status', UserStatus::Suspended)->count(),
            'allCount' => User::count(),
        ];
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <h1>회원 관리</h1>
            <p class="sub">정지된 회원을 되돌립니다. 가입 심사는 따로 있습니다.</p>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card-head">
            <button type="button" @class(['adm-btn', 'primary' => $tab === 'all']) wire:click="$set('tab', 'all')">전체 {{ $allCount }}</button>
            <button type="button" @class(['adm-btn', 'primary' => $tab === 'suspended']) wire:click="$set('tab', 'suspended')">이용정지 {{ $suspendedCount }}</button>

            <div class="spacer"></div>

            <div class="adm-filter">
                <label class="sr" for="kw">상호 검색</label>
                <input id="kw" type="search" wire:model.live.debounce.400ms="keyword" placeholder="상호로 찾기">
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
                        <x-admin.th sort="company_name" :current="$this->currentSort()">상호</x-admin.th>
                        <x-admin.th>대표자</x-admin.th>
                        <x-admin.th>이용 상태</x-admin.th>
                        <x-admin.th>심사 상태</x-admin.th>
                        <x-admin.th>매물</x-admin.th>
                        <x-admin.th>숨김</x-admin.th>
                        <x-admin.th>지역</x-admin.th>
                        <x-admin.th sort="created_at" :current="$this->currentSort()">가입일</x-admin.th>
                        <x-admin.th>관리</x-admin.th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($users as $u)
                        <tr @class(['off' => $u->status === App\Enums\UserStatus::Suspended])>
                            <td>#{{ $u->idx }}</td>
                            <td>{{ $u->company_name }}</td>
                            <td class="muted">{{ $u->name }}</td>
                            <td><span class="adm-badge {{ $u->status === App\Enums\UserStatus::Suspended ? 'off' : 'done' }}">{{ $u->status->label() }}</span></td>
                            <td><span class="adm-badge gray">{{ $u->review_status->label() }}</span></td>
                            @php $st = $postStats[$u->idx] ?? null; @endphp
                            <td class="num">
                                @if ($st && $st->c > 0)
                                    {{ number_format($st->c) }}
                                @else
                                    <span class="zero">0</span>
                                @endif
                            </td>
                            <td class="num">
                                @if ($st && $st->h > 0)
                                    <span class="adm-badge off">{{ $st->h }}</span>
                                @else
                                    <span class="zero">0</span>
                                @endif
                            </td>
                            <td class="muted">{{ $u->regionName() }}</td>
                            <td class="muted">{{ $u->created_at?->timezone('Asia/Seoul')->format('Y-m-d') }}</td>
                            <td><a class="adm-btn tiny" href="{{ route('admin.user', $u) }}">열기</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><div class="adm-empty">해당하는 회원이 없습니다.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <x-admin.pager :rows="$users" />
        </div>
    </div>
</div>
