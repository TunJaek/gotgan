<?php

/*
 * ┌─ 가입 심사 목록 ────────────────────────────────────────────────
 * │ 오는 길    GET /admin/applications  ·  auth + admin
 * │
 * │ 하는 일    제출된 가입 신청을 상태별로 본다. 처리는 상세에서 한다
 * │            작성중(Draft)은 제출 전이라 목록에 넣지 않는다
 * └────────────────────────────────────────────────────────────────
 */

use App\Concerns\AdminList;
use App\Enums\ApplicationStatus;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '가입 심사'])]
class extends Component
{
    use AdminList;

    /** 관리자가 볼 탭. 작성중(Draft)은 제출 전이라 보여주지 않는다. */
    private const TABS = [
        ApplicationStatus::Reviewing,
        ApplicationStatus::Supplement,
        ApplicationStatus::Approved,
        ApplicationStatus::Rejected,
    ];

    /** '' = 전체. 들어오면 전체가 먼저 보인다. */
    public string $tab = '';

    protected function sortable(): array
    {
        return ['idx', 'company_name', 'submitted_at', 'submission_revision'];
    }

    protected function defaultSort(): array
    {
        return ['submitted_at', 'desc'];
    }

    public function with(): array
    {
        $query = User::query()->whereNotNull('submitted_at');

        if ($this->tab !== '') {
            $query->where('review_status', (int) $this->tab);
        } else {
            // 전체라도 작성중은 제외한다. 제출한 적 없는 신청이다.
            $query->whereIn('review_status', array_map(fn ($c) => $c->value, self::TABS));
        }

        if ($this->term() !== '') {
            $query->where('company_name', 'like', '%'.$this->term().'%');
        }

        return [
            'applications' => $this->applySort($query)->paginate($this->per),
            'tabs' => self::TABS,
            'counts' => collect(self::TABS)
                ->mapWithKeys(fn ($case) => [$case->value => User::where('review_status', $case)->whereNotNull('submitted_at')->count()])
                ->all(),
            'allCount' => User::whereNotNull('submitted_at')
                ->whereIn('review_status', array_map(fn ($c) => $c->value, self::TABS))->count(),
        ];
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <h1>가입 심사</h1>
            <p class="sub">사업자등록증을 확인하고 승인합니다. 승인된 회원의 매물은 검수 없이 바로 노출됩니다.</p>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card-head">
            <button type="button" @class(['adm-btn', 'primary' => $tab === ''])
                    wire:click="$set('tab', '')">전체 {{ $allCount }}</button>
            @foreach ($tabs as $case)
                <button type="button" @class(['adm-btn', 'primary' => $tab === (string) $case->value])
                        wire:click="$set('tab', '{{ $case->value }}')">
                    {{ $case->label() }} {{ $counts[$case->value] }}
                </button>
            @endforeach

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
                        <x-admin.th>지역</x-admin.th>
                        <x-admin.th>심사 상태</x-admin.th>
                        <x-admin.th sort="submission_revision" :current="$this->currentSort()">재제출</x-admin.th>
                        <x-admin.th sort="submitted_at" :current="$this->currentSort()">접수일</x-admin.th>
                        <x-admin.th>심사</x-admin.th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($applications as $a)
                        <tr>
                            <td>#{{ $a->idx }}</td>
                            <td>{{ $a->company_name }}</td>
                            <td class="muted">{{ $a->name }}</td>
                            <td class="muted">{{ $a->regionName() }}</td>
                            <td>
                                <span class="adm-badge {{ match ($a->review_status) {
                                    App\Enums\ApplicationStatus::Reviewing => 'wait',
                                    App\Enums\ApplicationStatus::Approved => 'done',
                                    App\Enums\ApplicationStatus::Rejected => 'off',
                                    default => 'info',
                                } }}">{{ $a->review_status->label() }}</span>
                            </td>
                            <td class="num">
                                @if ($a->submission_revision > 1)
                                    <span class="adm-badge info">{{ $a->submission_revision }}회차</span>
                                @else
                                    <span class="zero">1회차</span>
                                @endif
                            </td>
                            <td class="muted">{{ $a->submitted_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</td>
                            <td><a class="adm-btn tiny primary" href="{{ route('admin.review', $a) }}">검토하기</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="adm-empty">해당 상태의 신청이 없습니다.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <x-admin.pager :rows="$applications" />
        </div>
    </div>
</div>
