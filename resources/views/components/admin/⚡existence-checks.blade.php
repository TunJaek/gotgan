<?php

use App\Concerns\AdminList;
use App\Enums\ExistenceCheckResult;
use App\Models\ExistenceCheck;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '존속 점검'])]
class extends Component
{
    use AdminList;

    public string $tab = 'all';

    protected function sortable(): array
    {
        return ['idx', 'checked_at', 'result'];
    }

    protected function defaultSort(): array
    {
        return ['checked_at', 'desc'];
    }

    public function with(): array
    {
        $query = ExistenceCheck::query()->with('user:idx,company_name,biz_no,status');

        if ($this->tab === 'attention') {
            $query->where('result', '!=', ExistenceCheckResult::Active);
        } elseif ($this->tab === 'failed') {
            $query->where('result', ExistenceCheckResult::Failed);
        } elseif ($this->tab === 'active') {
            $query->where('result', ExistenceCheckResult::Active);
        }

        if ($this->term() !== '') {
            $term = $this->term();
            $digits = preg_replace('/\D/', '', $term);

            $query->whereHas('user', function ($users) use ($term, $digits) {
                $users->where('company_name', 'like', '%'.$term.'%');

                if ($digits !== '') {
                    $users->orWhere('biz_no', 'like', '%'.$digits.'%');
                }
            });
        }

        return [
            'checks' => $this->applySort($query)->paginate($this->per),
            'counts' => [
                'all' => ExistenceCheck::count(),
                'attention' => ExistenceCheck::where('result', '!=', ExistenceCheckResult::Active)->count(),
                'failed' => ExistenceCheck::where('result', ExistenceCheckResult::Failed)->count(),
                'active' => ExistenceCheck::where('result', ExistenceCheckResult::Active)->count(),
            ],
        ];
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <h1>사업자 존속 점검</h1>
            <p class="sub">국세청 상태 조회의 결과와 시스템이 취한 조치를 확인합니다.</p>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card-head">
            @foreach ([['all', '전체'], ['attention', '확인 필요'], ['failed', '조회 실패'], ['active', '계속사업자']] as [$key, $label])
                <button type="button" @class(['adm-btn', 'primary' => $tab === $key])
                        wire:click="$set('tab', '{{ $key }}')">{{ $label }} {{ $counts[$key] }}</button>
            @endforeach

            <div class="spacer"></div>

            <div class="adm-filter">
                <label class="sr" for="kw">상호 또는 사업자번호 검색</label>
                <input id="kw" type="search" wire:model.live.debounce.400ms="keyword" placeholder="상호·사업자번호로 찾기">
                <span class="adm-loading" wire:loading>불러오는 중…</span>
                <x-admin.per-page />
            </div>
        </div>

        <div class="adm-card-body tight">
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr>
                        <x-admin.th sort="idx" :current="$this->currentSort()">번호</x-admin.th>
                        <x-admin.th sort="checked_at" :current="$this->currentSort()">점검 시각</x-admin.th>
                        <x-admin.th>상호</x-admin.th>
                        <x-admin.th>사업자번호</x-admin.th>
                        <x-admin.th sort="result" :current="$this->currentSort()">결과</x-admin.th>
                        <x-admin.th>원본 코드</x-admin.th>
                        <x-admin.th>시스템 조치</x-admin.th>
                        <x-admin.th>회원 관리</x-admin.th>
                    </tr></thead>
                    <tbody>
                    @forelse ($checks as $check)
                        <tr wire:key="existence-check-{{ $check->idx }}">
                            <td>#{{ $check->idx }}</td>
                            <td class="muted">{{ $check->checked_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</td>
                            <td>{{ $check->user?->company_name ?? '탈퇴 회원' }}</td>
                            <td class="muted">{{ $check->user?->bizNo() ?? '—' }}</td>
                            <td><span class="adm-badge {{ $check->result === ExistenceCheckResult::Active ? 'done' : ($check->result === ExistenceCheckResult::Failed ? 'wait' : 'off') }}">{{ $check->result->label() }}</span></td>
                            <td class="muted">{{ $check->raw_status_code ?: '—' }}</td>
                            <td class="wrap-cell">{{ $check->action_taken ?: '—' }}</td>
                            <td>
                                @if ($check->user)
                                    <a class="adm-btn tiny" href="{{ route('admin.user', $check->user) }}">열기</a>
                                @else
                                    <span class="zero">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="adm-empty">아직 기록된 존속 점검 결과가 없습니다.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <x-admin.pager :rows="$checks" />
        </div>
    </div>
</div>
