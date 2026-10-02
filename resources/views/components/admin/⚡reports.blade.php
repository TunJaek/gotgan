<?php

/*
 * ┌─ 신고 목록 ─────────────────────────────────────────────────────
 * │ 오는 길    GET /admin/reports  ·  auth + admin
 * │
 * │ 내놀 것    $groups — 대상별로 묶은 신고. 신고는 건별로 쌓이지만
 * │            같은 매물에 다섯 건이 있으면 한 줄로 보여주고 한 번에 처리한다
 * │
 * │ 나가는 길  행 클릭 → /admin/reports/{target_type}/{target_idx}
 * │
 * │ 정렬·페이지·검색 규칙은 App\Concerns\AdminList 에 있다.
 * └────────────────────────────────────────────────────────────────
 */

use App\Concerns\AdminList;
use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '신고'])]
class extends Component
{
    use AdminList;

    /** '' = 전체. 그 외에는 ReportStatus 값. 들어오면 전체가 먼저 보인다. */
    public string $tab = '';

    protected function sortable(): array
    {
        return ['report_count', 'last_reported_at', 'target_idx'];
    }

    protected function defaultSort(): array
    {
        return ['last_reported_at', 'desc'];
    }

    public function with(): array
    {
        // 대상별로 묶는다. 건수와 가장 최근 신고 시각만 있으면 목록을 그릴 수 있다.
        // 전체 탭에서는 한 대상에 상태가 섞이므로 상태별 건수를 따로 센다.
        $query = Report::query()
            ->select('target_type', 'target_idx',
                DB::raw('COUNT(*) as report_count'),
                DB::raw('SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as cnt_open'),
                // 반복 신고인지 진짜 문제인지는 건수가 아니라 '몇 사람이 신고했나'로 갈린다.
                // 한 사람이 다섯 번 낸 것과 다섯 사람이 낸 것은 전혀 다른 상황이다.
                DB::raw('COUNT(DISTINCT CASE WHEN status = 0 THEN reporter_idx END) as open_people'),
                DB::raw('SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as cnt_dismissed'),
                DB::raw('MAX(CASE WHEN status = 3 THEN updated_at END) as last_dismissed_at'),
                DB::raw('MAX(created_at) as last_reported_at'),
                DB::raw('MAX(idx) as idx'))
            ->groupBy('target_type', 'target_idx');

        if ($this->tab !== '') {
            $query->where('status', ReportStatus::from((int) $this->tab));
        }

        $groups = $this->applySort($query)->paginate($this->per);

        // 대상 이름을 붙인다. 종류별로 한 번씩만 조회해서 방마다 쿼리가 나가지 않게 한다.
        $posts = Post::with('user:idx,company_name')
            ->whereIn('idx', $groups->where('target_type', ReportTarget::Post)->pluck('target_idx'))
            ->get()->keyBy('idx');
        $users = User::whereIn('idx', $groups->where('target_type', ReportTarget::User)->pluck('target_idx'))
            ->get()->keyBy('idx');

        foreach ($groups as $g) {
            $post = $posts[$g->target_idx] ?? null;
            $user = $users[$g->target_idx] ?? null;

            $g->label = match ($g->target_type) {
                ReportTarget::Post => $post?->title ?? '(삭제된 매물)',
                ReportTarget::User => $user?->company_name ?? '(탈퇴한 회원)',
                default => '메시지 #'.$g->target_idx,
            };

            $g->owner = $post?->user?->company_name ?? ($user?->company_name ?? '');

            // 지금 대상이 어떤 상태인지. 이미 숨김·정지면 새로 할 조치가 없다.
            [$g->state, $g->state_tone] = match (true) {
                $post !== null => $post->is_hidden ? ['운영자 숨김', 'off'] : ['노출', 'done'],
                $user !== null => $user->status === App\Enums\UserStatus::Suspended ? ['이용정지', 'off'] : ['정상', 'done'],
                default => ['삭제됨', 'gray'],
            };
        }

        return [
            'groups' => $groups,
            'tabs' => ReportStatus::tabs(),
            'counts' => collect(ReportStatus::tabs())
                ->mapWithKeys(fn ($case) => [$case->value => Report::where('status', $case)->count()])
                ->all(),
            'allCount' => Report::count(),
        ];
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <h1>신고</h1>
            <p class="sub">같은 대상에 들어온 신고는 한 줄로 묶었습니다. 한 번 조치하면 함께 닫힙니다.</p>
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
                <label class="sr" for="kw">대상 검색</label>
                <input id="kw" type="search" wire:model.live.debounce.400ms="keyword" placeholder="대상 번호로 찾기">
                <span class="adm-loading" wire:loading>불러오는 중…</span>
                <x-admin.per-page />
            </div>
        </div>

        <div class="adm-card-body tight">
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                    <tr>
                        <x-admin.th>종류</x-admin.th>
                        <x-admin.th sort="target_idx" :current="$this->currentSort()">대상번호</x-admin.th>
                        <x-admin.th>대상</x-admin.th>
                        <x-admin.th>등록자</x-admin.th>
                        <x-admin.th>현재 상태</x-admin.th>
                        <x-admin.th sort="report_count" :current="$this->currentSort()">신고 건수</x-admin.th>
                        <x-admin.th>미처리</x-admin.th>
                        <x-admin.th>신고자</x-admin.th>
                        <x-admin.th>이전 판단</x-admin.th>
                        <x-admin.th sort="last_reported_at" :current="$this->currentSort()">최근 신고</x-admin.th>
                        <x-admin.th>처리</x-admin.th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($groups as $g)
                        <tr>
                            <td>{{ $g->target_type->label() }}</td>
                            <td>#{{ $g->target_idx }}</td>
                            <td>{{ $g->label }}</td>
                            <td class="muted">{{ $g->owner }}</td>
                            <td><span class="adm-badge {{ $g->state_tone }}">{{ $g->state }}</span></td>
                            <td class="num"><strong>{{ number_format($g->report_count) }}</strong></td>
                            <td class="num">
                                @if ($g->cnt_open > 0)
                                    <span class="adm-badge wait">{{ $g->cnt_open }}</span>
                                @else
                                    <span class="zero">0</span>
                                @endif
                            </td>
                            <td class="num">
                                @if ($g->open_people > 0)
                                    {{ $g->open_people }}명
                                @else
                                    <span class="zero">0명</span>
                                @endif
                            </td>
                            <td>
                                {{-- 전에 기각한 적이 있으면 알려준다. 같은 판단을 처음부터 다시 하지 않도록. --}}
                                @if ($g->cnt_dismissed > 0)
                                    <span class="adm-badge gray" title="기각 {{ $g->cnt_dismissed }}건">
                                        기각 {{ \Illuminate\Support\Carbon::parse($g->last_dismissed_at)->timezone('Asia/Seoul')->format('n/j') }}
                                    </span>
                                @else
                                    <span class="zero">없음</span>
                                @endif
                            </td>
                            <td class="muted">{{ \Illuminate\Support\Carbon::parse($g->last_reported_at)->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</td>
                            <td>
                                <a class="adm-btn tiny primary"
                                   href="{{ route('admin.report', ['type' => $g->target_type->value, 'idx' => $g->target_idx]) }}">신고 확인</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11"><div class="adm-empty">해당 상태의 신고가 없습니다.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <x-admin.pager :rows="$groups" />
        </div>
    </div>
</div>
