<?php

/*
 * ┌─ 신고 처리 ─────────────────────────────────────────────────────
 * │ 오는 길    GET /admin/reports/{type}/{idx}  ·  auth + admin
 * │            {type} 은 ReportTarget 값, {idx} 는 그 대상의 idx
 * │
 * │ 하는 일    한 대상에 들어온 신고를 모아 보여주고, 한 번에 조치한다
 * │            조치: 글 숨김 / 회원 정지 / 기각
 * │            되돌리기는 여기 없다 — 매물·회원 관리 화면에서 한다
 * │
 * │ 남기는 것  대상 로그 1건 + 닫은 신고마다 로그 1건 (App\Support\Moderation)
 * └────────────────────────────────────────────────────────────────
 */

use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Inquiry;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\Moderation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '신고 처리'])]
class extends Component
{
    #[Locked]
    public int $type;

    #[Locked]
    public int $idx;

    /** 함께 닫을 신고 번호. 미처리 신고를 기본으로 전부 고른다. */
    public array $selected = [];

    public string $reason = '';

    public ?string $notice = null;

    public function mount(int $type, int $idx): void
    {
        abort_unless(ReportTarget::tryFrom($type), 404);

        $this->type = $type;
        $this->idx = $idx;
        $this->selectAll();

        // 회원 신고는 신고자와의 대화를 보여 준다. 운영자가 회원 대화를 본 사실을 남긴다(등록증 열람과 같은 방식).
        if (ReportTarget::from($type) === ReportTarget::User) {
            AuditLog::record('report.conversation.view', 'user', $idx);
        }
    }

    public function with(): array
    {
        $target_type = ReportTarget::from($this->type);
        $target = $this->targetModel();

        return [
            'targetType' => $target_type,
            'target' => $target,
            'targetLabel' => match (true) {
                $target instanceof Post => $target->title,
                $target instanceof User => $target->company_name,
                default => '#'.$this->idx,
            },
            // 이미 조치된 대상이면 새로 바꿀 것이 없다. 신고만 닫는 버튼을 보여준다.
            'alreadyActed' => match (true) {
                $target instanceof Post => (bool) $target->is_hidden,
                $target instanceof User => $target->status === UserStatus::Suspended,
                default => false,
            },
            'unresolved' => $this->unresolved(),
            // 전에 기각한 적이 있으면 그 판단을 조치 버튼 위에 그대로 보여준다.
            // 같은 대상을 처음부터 다시 판단하지 않게 하려는 것이지,
            // 새 신고를 가볍게 보이게 하려는 것이 아니다.
            'lastDismissed' => $this->lastDismissed(),
            'dismissReason' => $this->lastDismissReason(),
            'history' => Report::query()
                ->where('target_type', $target_type)
                ->where('target_idx', $this->idx)
                ->where('status', '!=', ReportStatus::Received)
                ->with('reporter:idx,company_name')
                ->orderByDesc('updated_at')
                ->get(),
            'conversations' => $target instanceof User ? $this->conversations($target) : collect(),
            'logs' => AuditLog::query()
                ->where('target_type', $target_type->auditType())
                ->where('target_idx', $this->idx)
                ->where('action', '!=', 'report.conversation.view')   // 열람 기록은 조치 이력이 아니다
                ->with('actor:idx,company_name')
                ->orderByDesc('occurred_at')
                ->limit(10)
                ->get(),
        ];
    }

    /** 대상을 숨기거나 정지하고 고른 신고를 닫는다. */
    public function act(): void
    {
        $picked = $this->pickedReports();

        if ($picked->isEmpty()) {
            $this->addError('selected', '처리할 신고를 선택해 주세요.');

            return;
        }

        $target = $this->targetModel();

        if (! $target) {
            $this->addError('selected', '대상이 이미 삭제되었습니다. 기각으로 닫아 주세요.');

            return;
        }

        $reason = trim($this->reason) ?: null;
        $idxs = $picked->pluck('idx')->all();

        if ($target instanceof Post) {
            Moderation::hidePost($target, $reason, $idxs);
            $this->notice = '매물을 숨기고 신고 '.count($idxs).'건을 조치완료로 닫았습니다.';
        } elseif ($target instanceof User) {
            Moderation::suspendUser($target, $reason, $idxs);
            $this->notice = '회원을 정지하고 신고 '.count($idxs).'건을 조치완료로 닫았습니다. 이 회원의 매물도 함께 숨겼습니다.';
        } else {
            Moderation::resolveReports($idxs, $reason);
            $this->notice = '신고 '.count($idxs).'건을 조치완료로 닫았습니다.';
        }

        $this->afterAct();
    }

    /** 대상은 그대로 두고 신고만 닫는다. 이미 숨김·정지된 대상에 쓴다. */
    public function resolveOnly(): void
    {
        $picked = $this->pickedReports();

        if ($picked->isEmpty()) {
            $this->addError('selected', '처리할 신고를 선택해 주세요.');

            return;
        }

        Moderation::resolveReports($picked->pluck('idx')->all(), trim($this->reason) ?: null);
        $this->notice = '신고 '.$picked->count().'건을 조치완료로 닫았습니다. 대상 상태는 그대로입니다.';
        $this->afterAct();
    }

    public function dismiss(): void
    {
        $picked = $this->pickedReports();

        if ($picked->isEmpty()) {
            $this->addError('selected', '처리할 신고를 선택해 주세요.');

            return;
        }

        Moderation::dismissReports($picked->pluck('idx')->all(), trim($this->reason) ?: null);
        $this->notice = '신고 '.$picked->count().'건을 기각했습니다.';
        $this->afterAct();
    }

    public function selectAll(): void
    {
        $this->selected = $this->unresolved()->pluck('idx')->map(fn ($i) => (string) $i)->all();
    }

    public function selectNone(): void
    {
        $this->selected = [];
    }

    /** 화면에서 고른 것 중 아직 미처리인 신고만. 그사이 남이 처리했을 수 있다. */
    private function pickedReports()
    {
        return $this->unresolved()->whereIn('idx', array_map('intval', $this->selected));
    }

    private function unresolved()
    {
        return Report::query()
            ->where('target_type', $this->type)
            ->where('target_idx', $this->idx)
            ->unresolved()
            ->with('reporter:idx,company_name')
            ->orderBy('idx')
            ->get();
    }

    /** 이 대상에 마지막으로 기각된 신고. 없으면 null. */
    private function lastDismissed(): ?Report
    {
        return Report::query()
            ->where('target_type', $this->type)
            ->where('target_idx', $this->idx)
            ->where('status', ReportStatus::Dismissed)
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * 그때 운영자가 적은 처리 사유.
     *
     * 신고자가 쓴 사유(reports.reason)와 다른 글이다. 그건 신고 내용이고
     * 이건 '왜 기각했나'다. 기각 로그는 신고 번호에 달리므로 그걸로 찾는다.
     */
    private function lastDismissReason(): ?string
    {
        $dismissed = $this->lastDismissed();

        if (! $dismissed) {
            return null;
        }

        return AuditLog::query()
            ->where('action', 'report.dismissed')
            ->where('target_type', 'report')
            ->where('target_idx', $dismissed->idx)
            ->orderByDesc('occurred_at')
            ->value('reason');
    }

    /**
     * 미처리 신고의 신고자마다, 그 사람과 신고된 회원의 최근 대화 30건.
     * 방을 찾기만 한다 — 관리자가 본다고 빈 방이 생기면 안 된다.
     */
    private function conversations(User $target)
    {
        return $this->unresolved()
            ->pluck('reporter')
            ->unique('idx')
            ->map(function (User $reporter) use ($target) {
                $room = Inquiry::findBetween($reporter->idx, $target->idx);

                return (object) [
                    'reporter' => $reporter,
                    'messages' => $room
                        ? $room->messages()->orderByDesc('idx')->limit(30)->get()->reverse()
                        : collect(),
                ];
            });
    }

    private function targetModel(): Post|User|null
    {
        return match (ReportTarget::from($this->type)) {
            ReportTarget::Post => Post::with('user')->find($this->idx),
            ReportTarget::User => User::find($this->idx),
            default => null,
        };
    }

    private function afterAct(): void
    {
        $this->reason = '';
        $this->resetErrorBag();
        $this->selectAll();
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <a class="back" href="{{ route('admin.reports') }}">{!! App\Support\Icon::render('back') !!}신고 목록</a>
            <h1>{{ $targetType->label() }} #{{ $idx }}</h1>
            <p class="sub">{{ $targetLabel }}</p>
        </div>
        <div class="adm-head-actions">
            @if ($targetType === App\Enums\ReportTarget::Post && $target)
                <a class="adm-btn" href="{{ route('posts.detail', $target) }}">매물 상세</a>
                <a class="adm-btn" href="{{ route('admin.post', $target) }}">매물 관리</a>
                @if ($target->user)
                    <a class="adm-btn" href="{{ route('admin.user', $target->user) }}">회원 관리</a>
                @endif
            @elseif ($targetType === App\Enums\ReportTarget::User && $target)
                <a class="adm-btn" href="{{ route('admin.user', $target) }}">회원 관리</a>
            @endif
        </div>
    </div>

    @if ($notice)
        <div class="adm-notice">{!! App\Support\Icon::render('check') !!}<span>{{ $notice }}</span></div>
    @endif

    <div class="adm-card">
        <div class="adm-card-head"><h2>대상</h2></div>
        <div class="adm-card-body tight">
            <dl class="adm-facts">
                <dt>종류</dt><dd>{{ $targetType->label() }} #{{ $idx }}</dd>
                <dt>이름</dt><dd>{{ $targetLabel }}</dd>
                @if ($target instanceof App\Models\Post)
                    <dt>현재 상태</dt>
                    <dd>
                        <span class="adm-badge {{ $target->is_hidden ? 'off' : 'done' }}">{{ $target->is_hidden ? '운영자 숨김' : '노출' }}</span>
                        <span class="adm-badge gray">{{ $target->status->label($target->post_type) }}</span>
                    </dd>
                    <dt>판매자</dt><dd>{{ $target->user->company_name ?? '탈퇴 회원' }}</dd>
                    <dt>설명</dt><dd style="white-space:pre-wrap">{{ \Illuminate\Support\Str::limit($target->description, 400) }}</dd>
                @elseif ($target instanceof App\Models\User)
                    <dt>현재 상태</dt>
                    <dd><span class="adm-badge {{ $target->status === App\Enums\UserStatus::Suspended ? 'off' : 'done' }}">{{ $target->status->label() }}</span></dd>
                @else
                    <dt>현재 상태</dt><dd class="muted">대상이 삭제되었습니다. 기각으로 닫아 주세요.</dd>
                @endif
            </dl>
        </div>
    </div>

    {{-- 회원 신고: 신고자마다 신고된 회원과 나눈 최근 대화. 이 화면을 열면 audit_logs 에 열람이 남는다. --}}
    @if ($conversations->isNotEmpty())
        <div class="adm-card">
            <div class="adm-card-head">
                <h2>신고자와의 대화</h2>
                <div class="spacer"></div>
                <span class="muted">최근 30건 · 열람 기록이 남습니다</span>
            </div>
            <div class="adm-card-body">
                @foreach ($conversations as $c)
                    <h3 style="margin:4px 0 8px">{{ $c->reporter->company_name }} ↔ {{ $targetLabel }}</h3>
                    @forelse ($c->messages as $m)
                        <div style="padding:6px 0;border-bottom:1px solid var(--line)">
                            <span class="adm-badge {{ $m->sender_idx === $idx ? 'off' : 'gray' }}">
                                {{ $m->sender_idx === $idx ? '신고된 회원' : '신고자' }}
                            </span>
                            <span class="muted">{{ $m->created_at->timezone('Asia/Seoul')->format('m-d H:i') }}</span>
                            <div style="white-space:pre-wrap;margin-top:4px">{{ $m->body }}</div>
                        </div>
                    @empty
                        <p class="muted">두 회원이 주고받은 메시지가 없습니다.</p>
                    @endforelse
                    @unless ($loop->last) <div style="height:18px"></div> @endunless
                @endforeach
            </div>
        </div>
    @endif

    <div class="adm-card">
        <div class="adm-card-head">
            <h2>미처리 신고 {{ $unresolved->count() }}건</h2>
            @if ($unresolved->isNotEmpty())
                <div class="spacer"></div>
                <button type="button" class="adm-btn tiny" wire:click="selectAll">전체 선택</button>
                <button type="button" class="adm-btn tiny" wire:click="selectNone">선택 해제</button>
            @endif
        </div>

        <div class="adm-card-body">
            @forelse ($unresolved as $report)
                <label class="adm-pick">
                    <input type="checkbox" wire:model="selected" value="{{ $report->idx }}">
                    <span class="body">
                        <strong>신고 #{{ $report->idx }} · {{ $report->reporter->company_name ?? '탈퇴 회원' }}</strong>
                        <p>{{ $report->reason }}</p>
                        <small>{{ $report->created_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</small>
                    </span>
                </label>
            @empty
                <div class="adm-empty">미처리 신고가 없습니다.</div>
            @endforelse

            @error('selected') <p class="error">{{ $message }}</p> @enderror

            {{-- 전에 기각한 적이 있으면 조치를 고르기 전에 그 판단을 먼저 읽게 한다. --}}
            @if ($lastDismissed && $unresolved->isNotEmpty())
                <div class="adm-prior">
                    <strong>이 대상은 전에 기각한 적이 있습니다</strong>
                    <p>
                        {{ $lastDismissed->updated_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }}
                        · 신고 #{{ $lastDismissed->idx }}
                    </p>
                    @if ($dismissReason)
                        <p class="why">당시 처리 사유: {{ $dismissReason }}</p>
                    @else
                        <p class="why">당시 처리 사유가 적혀 있지 않습니다.</p>
                    @endif
                    <small>같은 판단을 다시 하실 필요는 없지만, 새 신고는 새로 보셔야 합니다.</small>
                </div>
            @endif

            @if ($unresolved->isNotEmpty())
                <div class="field" style="margin-top:18px;margin-bottom:12px">
                    <label for="reason">운영자 처리 사유</label>
                    <textarea id="reason" wire:model="reason" style="min-height:80px"
                              placeholder="왜 이렇게 처리했는지 적어 두면 나중에 복구할 때 도움이 됩니다."></textarea>
                    <small>신고자가 쓴 사유와 따로 기록됩니다.</small>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    @if ($alreadyActed)
                        {{-- 이미 숨김·정지된 대상이다. 상태를 또 바꿀 것이 없으니 신고만 닫는다. --}}
                        <button type="button" class="adm-btn primary" wire:click="resolveOnly">기존 조치로 {{ count($selected) }}건 종결</button>
                    @elseif ($targetType === App\Enums\ReportTarget::Post && $target)
                        <button type="button" class="adm-btn danger" wire:click="act">글 숨김 + {{ count($selected) }}건 조치완료</button>
                    @elseif ($targetType === App\Enums\ReportTarget::User && $target)
                        <button type="button" class="adm-btn danger" wire:click="act">회원 정지 + {{ count($selected) }}건 조치완료</button>
                    @endif
                    <button type="button" class="adm-btn" wire:click="dismiss">{{ count($selected) }}건 기각</button>
                </div>
            @endif
        </div>
    </div>

    @if ($history->isNotEmpty())
        <div class="adm-card">
            <div class="adm-card-head"><h2>처리 이력</h2></div>
            <div class="adm-card-body tight">
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>결과</th><th>신고번호</th><th>신고자</th><th>신고 사유</th><th>처리 시각</th></tr></thead>
                        <tbody>
                        @foreach ($history as $report)
                            <tr>
                                <td><span class="adm-badge {{ $report->status === App\Enums\ReportStatus::Resolved ? 'done' : 'gray' }}">{{ $report->status->label() }}</span></td>
                                <td>#{{ $report->idx }}</td>
                                <td>{{ $report->reporter->company_name ?? '탈퇴 회원' }}</td>
                                <td class="muted">{{ \Illuminate\Support\Str::limit($report->reason, 50) }}</td>
                                <td class="muted">{{ $report->updated_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if ($logs->isNotEmpty())
        <div class="adm-card">
            <div class="adm-card-head"><h2>조치 기록</h2></div>
            <div class="adm-card-body tight">
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>행위</th><th>처리자</th><th>사유</th><th>시각</th></tr></thead>
                        <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td><span class="adm-badge gray">{{ $log->label() }}</span></td>
                                <td>{{ $log->actorLabel() }}</td>
                                <td class="muted">{{ $log->reason }}</td>
                                <td class="muted">{{ $log->occurred_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
