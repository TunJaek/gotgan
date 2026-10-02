<?php

use App\Concerns\AdminList;
use App\Models\NotificationLog;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '문자 발송 이력'])]
class extends Component
{
    use AdminList;

    public string $tab = 'all';

    protected function sortable(): array
    {
        return ['idx', 'sent_at', 'type', 'success'];
    }

    protected function defaultSort(): array
    {
        return ['sent_at', 'desc'];
    }

    public function with(): array
    {
        $query = NotificationLog::query()->with('recipient:idx,company_name,biz_no');

        if ($this->tab === 'success') {
            $query->where('success', true);
        } elseif ($this->tab === 'failed') {
            $query->where('success', false);
        }

        if ($this->term() !== '') {
            $term = $this->term();
            $query->where(function ($logs) use ($term) {
                $logs->where('provider_message_id', 'like', '%'.$term.'%')
                    ->orWhereHas('recipient', fn ($users) => $users->where('company_name', 'like', '%'.$term.'%'));
            });
        }

        return [
            'logs' => $this->applySort($query)->paginate($this->per),
            'counts' => [
                'all' => NotificationLog::count(),
                'success' => NotificationLog::where('success', true)->count(),
                'failed' => NotificationLog::where('success', false)->count(),
            ],
        ];
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <h1>문자 발송 이력</h1>
            <p class="sub">가입 심사 결과 문자의 성공 여부와 실패 사유를 확인합니다.</p>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card-head">
            @foreach ([['all', '전체'], ['success', '발송 성공'], ['failed', '발송 실패']] as [$key, $label])
                <button type="button" @class(['adm-btn', 'primary' => $tab === $key])
                        wire:click="$set('tab', '{{ $key }}')">{{ $label }} {{ $counts[$key] }}</button>
            @endforeach

            <div class="spacer"></div>

            <div class="adm-filter">
                <label class="sr" for="kw">상호 또는 발송 번호 검색</label>
                <input id="kw" type="search" wire:model.live.debounce.400ms="keyword" placeholder="상호·발송 번호로 찾기">
                <span class="adm-loading" wire:loading>불러오는 중…</span>
                <x-admin.per-page />
            </div>
        </div>

        <div class="adm-card-body tight">
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead><tr>
                        <x-admin.th sort="idx" :current="$this->currentSort()">번호</x-admin.th>
                        <x-admin.th sort="sent_at" :current="$this->currentSort()">발송 시각</x-admin.th>
                        <x-admin.th>수신 상호</x-admin.th>
                        <x-admin.th sort="type" :current="$this->currentSort()">종류</x-admin.th>
                        <x-admin.th sort="success" :current="$this->currentSort()">결과</x-admin.th>
                        <x-admin.th>발송 번호</x-admin.th>
                        <x-admin.th>실패 사유</x-admin.th>
                        <x-admin.th>심사 확인</x-admin.th>
                    </tr></thead>
                    <tbody>
                    @forelse ($logs as $log)
                        <tr wire:key="notification-log-{{ $log->idx }}">
                            <td>#{{ $log->idx }}</td>
                            <td class="muted">{{ $log->sent_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</td>
                            <td>{{ $log->recipient?->company_name ?? '탈퇴 회원' }}</td>
                            <td><span class="adm-badge gray">{{ $log->type->label() }}</span></td>
                            <td><span class="adm-badge {{ $log->success ? 'done' : 'off' }}">{{ $log->success ? '성공' : '실패' }}</span></td>
                            <td class="muted">{{ $log->provider_message_id ?: '—' }}</td>
                            <td class="wrap-cell">{{ $log->error_message ?: '—' }}</td>
                            <td>
                                @if ($log->recipient)
                                    <a class="adm-btn tiny" href="{{ route('admin.review', $log->recipient) }}">열기</a>
                                @else
                                    <span class="zero">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="adm-empty">기록된 문자 발송 이력이 없습니다.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <x-admin.pager :rows="$logs" />
        </div>
    </div>
</div>
