<?php

/*
 * ┌─ 관리자 홈 ─────────────────────────────────────────────────────
 * │ 오는 길    GET /admin  ·  auth + admin
 * │            헤더에 링크를 걸지 않는다. 주소를 직접 쳐서 들어온다.
 * │
 * │ 내놀 것    손봐야 할 건수. 숫자가 0이면 들어가지 않아도 된다
 * └────────────────────────────────────────────────────────────────
 */

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceCheckResult;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ExistenceCheck;
use App\Models\NotificationLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '홈'])]
class extends Component
{
    public function with(): array
    {
        return [
            'tiles' => [
                ['가입 심사', User::where('review_status', ApplicationStatus::Reviewing)->whereNotNull('submitted_at')->count(),
                    '건 대기', '사업자등록증을 확인하고 승인합니다.', route('admin.applications')],
                ['미처리 신고', Report::unresolved()->count(),
                    '건', '신고된 매물을 확인하고 조치합니다.', route('admin.reports')],
                ['숨긴 매물', Post::where('is_hidden', true)->count(),
                    '건', '운영자가 숨긴 매물을 되돌립니다.', route('admin.posts')],
                ['정지 회원', User::where('status', UserStatus::Suspended)->count(),
                    '명', '정지된 회원을 되돌립니다.', route('admin.users')],
                ['존속 점검 이상', ExistenceCheck::where('result', '!=', ExistenceCheckResult::Active)->count(),
                    '건', '조회 실패·휴폐업 기록을 확인합니다.', route('admin.existence-checks')],
                ['문자 발송 실패', NotificationLog::where('success', false)->count(),
                    '건', '심사 결과 문자의 실패 사유를 확인합니다.', route('admin.notification-logs')],
            ],
            'stats' => [
                ['전체 회원', User::count()],
                ['승인 회원', User::where('review_status', ApplicationStatus::Approved)->count()],
                ['판매중 매물', Post::where('status', PostStatus::Selling)->where('is_hidden', false)->count()],
                ['오늘 신고', Report::whereDate('created_at', today())->count()],
            ],
            'logs' => AuditLog::with('actor:idx,company_name')->orderByDesc('occurred_at')->limit(8)->get(),
        ];
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <h1>관리자</h1>
            <p class="sub">손볼 곳이 있는지 먼저 봅니다. 숫자가 0이면 들어가지 않아도 됩니다.</p>
        </div>
    </div>

    <div class="adm-tiles">
        @foreach ($tiles as [$label, $count, $unit, $note, $url])
            <a class="adm-tile" href="{{ $url }}">
                <span class="lbl">{{ $label }}</span>
                <span @class(['num', 'zero' => $count === 0, 'alert' => $count > 0])>{{ number_format($count) }}<span style="font-size:15px;font-weight:600">{{ $unit }}</span></span>
                <span class="note">{{ $note }}</span>
            </a>
        @endforeach
    </div>

    <div class="adm-card">
        <div class="adm-card-head"><h2>현황</h2></div>
        <div class="adm-card-body tight">
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                    <tr>@foreach ($stats as [$label, $n])<th>{{ $label }}</th>@endforeach</tr>
                    </thead>
                    <tbody>
                    <tr>@foreach ($stats as [$label, $n])<td class="num">{{ number_format($n) }}</td>@endforeach</tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card-head"><h2>최근 조치</h2></div>
        <div class="adm-card-body tight">
            <div class="adm-scroll">
                <table class="adm-table">
                    <thead>
                    <tr>
                        <th>시각</th>
                        <th>행위</th>
                        <th>대상</th>
                        <th>처리자</th>
                        <th>사유</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="muted">{{ $log->occurred_at->timezone('Asia/Seoul')->format('m-d H:i') }}</td>
                            <td><span class="adm-badge gray">{{ $log->label() }}</span></td>
                            <td>{{ $log->target_type }} #{{ $log->target_idx }}</td>
                            <td>{{ $log->actorLabel() }}</td>
                            <td class="muted">{{ \Illuminate\Support\Str::limit($log->reason, 40) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="adm-empty">아직 조치 기록이 없습니다.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
