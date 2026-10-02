<?php

use App\Enums\ApplicationStatus;
use App\Enums\ReviewDecision;
use App\Models\ApplicationReview;
use App\Models\User;
use App\Models\AuditLog;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '가입 심사'])]
class extends Component
{
    #[Locked]
    public int $userIdx;

    /** 화면을 연 시점의 버전. save() 에서 다시 읽으면 낙관적 잠금이 무의미해진다. */
    #[Locked]
    public int $lockVersion;


    public int $decision = 3;    // ReviewDecision::Approved
    public string $reason = '';

    public ?string $resultTitle = null;      // 처리 결과 모달
    public ?string $resultBody = null;
    public bool $resultFailed = false;

    public function mount(User $user): void
    {
        $this->userIdx = $user->idx;
        $this->lockVersion = (int) ($user->lock_version ?? 0);
    }

    public function with(): array
    {
        $applicant = User::with('reviews.reviewer')->findOrFail($this->userIdx);

        return [
            'applicant' => $applicant,
        ];
    }

    public function save(): void
    {
        $applicant = User::findOrFail($this->userIdx);

        if ($applicant->review_status->isFinal()) {
            $this->addError('decision', '이미 처리된 신청입니다.');

            return;
        }

        $decision = ReviewDecision::tryFrom($this->decision);

        if (! $decision) {
            $this->addError('decision', '심사 결과를 선택해 주세요.');

            return;
        }

        // 보완·반려는 신청자에게 보낼 이유가 반드시 있어야 한다.
        if ($decision->requiresReason() && trim($this->reason) === '') {
            $this->addError('reason', '신청자에게 보낼 이유를 적어 주세요.');

            return;
        }

        $lockVersion = $this->lockVersion;
        $reason = trim($this->reason) ?: null;

        // 상태 변경과 심사 이력을 한 트랜잭션에 묶는다.
        $applied = DB::transaction(function () use ($applicant, $lockVersion, $decision, $reason) {
            $affected = User::where('idx', $applicant->idx)
                ->where('lock_version', $lockVersion)
                ->update([
                    'review_status' => $decision->toApplicationStatus(),
                    'approved_at' => $decision === ReviewDecision::Approved ? now() : null,
                    'lock_version' => $lockVersion + 1,
                    'submission_revision' => $decision === ReviewDecision::Supplement
                        ? $applicant->submission_revision + 1
                        : $applicant->submission_revision,
                ]);

            if ($affected === 0) {
                return false;   // 다른 관리자가 먼저 처리했다
            }

            ApplicationReview::create([
                'user_idx' => $applicant->idx,
                'reviewer_idx' => auth()->id(),
                'decision' => $decision,
                'reason' => $reason,
                'reviewed_at' => now(),
            ]);

            // 숫자(application.3)가 아니라 이름(application.approved)으로 남긴다. 표시는 AuditLog::LABELS.
            AuditLog::record('application.'.strtolower($decision->name), 'user', $applicant->idx, $reason);

            return true;
        });

        if (! $applied) {
            $this->addError('decision', '다른 관리자가 먼저 처리했습니다. 화면을 새로 고쳐 주세요.');

            return;
        }

        $this->lockVersion = $lockVersion + 1;

        // 발송 실패가 승인을 되돌리지는 않는다. 결과만 그 자리에서 보여준다.
        $sent = Notifier::reviewResult($applicant->fresh(), $decision, $reason);

        $this->resultFailed = ! $sent['success'];
        $this->resultTitle = match ($decision) {
            ReviewDecision::Approved => '승인 처리했습니다',
            ReviewDecision::Supplement => '보완을 요청했습니다',
            default => '반려 처리했습니다',
        };
        $this->resultBody = $sent['message'];
        $this->reason = '';
    }

    public function resend(): void
    {
        $applicant = User::findOrFail($this->userIdx);
        $last = $applicant->reviews()->first();

        if (! $last) {
            $this->addError('decision', '보낼 심사 결과가 없습니다.');

            return;
        }

        $sent = Notifier::reviewResult($applicant, $last->decision, $last->reason);

        $this->resultFailed = ! $sent['success'];
        $this->resultTitle = '문자를 다시 보냈습니다';
        $this->resultBody = $sent['message'];
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <a class="back" href="{{ route('admin.applications') }}">← 가입 신청 목록</a>
            <h1>{{ $applicant->company_name }} 가입 심사</h1>
            <p class="sub">{{ $applicant->submitted_at?->timezone('Asia/Seoul')->format('Y년 n월 j일') }} 접수
                @if ($applicant->submission_revision > 1) · 재제출 {{ $applicant->submission_revision }}회차 @endif
            </p>
        </div>
        <div class="adm-head-actions">
            <a class="adm-btn" href="{{ route('admin.license', $applicant) }}" target="_blank" rel="noopener">사업자등록증 보기</a>
        </div>
    </div>

    @if ($resultTitle)
        <div @class(['adm-notice', 'bad' => $resultFailed])>
            <div><strong>{{ $resultTitle }}</strong><br><span>{{ $resultBody }}</span></div>
            @if ($resultFailed)
                <button type="button" class="adm-btn tiny" wire:click="resend">문자 다시 보내기</button>
            @endif
        </div>
    @endif

    <section class="adm-card">
        <div class="adm-card-head"><h2>신청 정보</h2></div>
        <div class="adm-card-body tight">
            <dl class="adm-facts">
                <dt>사업체명</dt><dd>{{ $applicant->company_name }}</dd>
                <dt>사업자번호</dt><dd>{{ $applicant->bizNo() }}</dd>
                <dt>대표자</dt><dd>{{ $applicant->name }}</dd>
                <dt>연락처</dt><dd>{{ $applicant->formattedPhone() }}</dd>
                <dt>이메일</dt><dd>{{ $applicant->email ?: '미입력' }}</dd>
                <dt>사업장 지역</dt><dd>{{ $applicant->regionName(full: true) }}</dd>
                <dt>국세청 조회</dt>
                <dd>{{ $applicant->existence_status->label() }}
                    @if ($applicant->existence_checked_at)
                        · {{ $applicant->existence_checked_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }}
                    @endif
                </dd>
                <dt>현재 상태</dt><dd><span class="adm-badge gray">{{ $applicant->review_status->label() }}</span></dd>
            </dl>
        </div>
    </section>

    @if (! $applicant->review_status->isFinal())
        <section class="adm-card">
            <div class="adm-card-head"><h2>심사 처리</h2></div>
            <div class="adm-card-body">
            <h3 class="adm-section-title">심사 결과</h3>
            <div class="adm-options">
                @foreach (ReviewDecision::cases() as $case)
                    <label>
                        <input type="radio" wire:model.live="decision" value="{{ $case->value }}"><span>{{ $case->label() }}</span>
                    </label>
                @endforeach
            </div>

            <div class="field adm-reason">
                <label for="reason">신청자에게 보낼 이유 @if ($decision !== ReviewDecision::Approved->value) <span class="dim">(필수)</span> @endif</label>
                <textarea id="reason" rows="4" wire:model="reason"
                          placeholder="예: 등록증 사진이 흐려 상호를 확인할 수 없습니다. 다시 올려 주세요."></textarea>
                @error('reason') <p class="error">{{ $message }}</p> @enderror
            </div>

            @error('decision') <p class="error">{{ $message }}</p> @enderror

            <button type="button" class="adm-btn primary" wire:click="save">
                {{ ReviewDecision::tryFrom($decision)?->label() }} 저장
            </button>
            <span class="adm-loading" wire:loading wire:target="save,resend">처리 중…</span>
            </div>
        </section>
    @endif

    <section class="adm-card">
        <div class="adm-card-head"><h2>심사 이력</h2></div>
        <div class="adm-card-body tight"><div class="adm-scroll"><table class="adm-table">
            <thead><tr><th>처리 시각</th><th>결과</th><th>처리자</th><th>사유</th></tr></thead>
            <tbody>
            @forelse ($applicant->reviews as $review)
                <tr wire:key="application-review-{{ $review->idx }}">
                    <td class="muted">{{ $review->reviewed_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</td>
                    <td><span class="adm-badge {{ $review->decision === ReviewDecision::Rejected ? 'off' : ($review->decision === ReviewDecision::Approved ? 'done' : 'wait') }}">{{ $review->decision->label() }}</span></td>
                    <td>{{ $review->reviewer?->name ?? '알 수 없음' }}</td>
                    <td class="wrap-cell">{{ $review->reason ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4"><div class="adm-empty">아직 심사 기록이 없습니다.</div></td></tr>
            @endforelse
            </tbody>
        </table></div></div>
    </section>
</div>
