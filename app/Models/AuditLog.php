<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $primaryKey = 'idx';

    protected $fillable = ['actor_idx', 'action', 'target_type', 'target_idx', 'reason', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * 관리자 화면 '행위' 칸에 보일 이름. 저장값은 영어 코드 그대로 두고 보여줄 때만 바꾼다.
     * 새 행위를 기록하기 시작하면 여기에도 한 줄 더한다. 없으면 저장값이 그대로 보인다.
     */
    public const LABELS = [
        'application.supplement' => '가입 보완 요청',
        'application.approved' => '가입 승인',
        'application.rejected' => '가입 반려',
        'application.resubmitted' => '보완 자료 재제출',
        'application.license.view' => '사업자등록증 열람',
        'post.hidden' => '매물 숨김',
        'post.hidden.by_suspend' => '매물 숨김 (회원 정지)',
        'post.hidden.by_withdraw' => '매물 숨김 (회원 탈퇴)',
        'post.unhidden' => '매물 숨김 해제',
        'post.unhidden.by_reinstate' => '매물 숨김 해제 (정지 해제)',
        'user.suspended' => '이용정지',
        'user.reinstated' => '이용정지 해제',
        'user.withdrawn' => '회원 탈퇴',
        'user.withdrawn.by_admin' => '회원 탈퇴 (운영자 처리)',
        'user.profile.changed' => '상호·대표자명 변경 (운영자)',
        'user.identity.reset' => '휴대폰·본인인증 초기화 (운영자)',
        'user.purged' => '반려 신청 개인정보 삭제',
        'user.password.reset' => '비밀번호 재설정',
        'user.password.changed' => '비밀번호 변경',
        'user.phone.changed' => '휴대폰 번호 변경',
        'report.resolved' => '신고 조치완료',
        'report.dismissed' => '신고 기각',
        'report.conversation.view' => '신고 대화 열람',
    ];

    public function label(): string
    {
        return self::LABELS[$this->action] ?? $this->action;
    }

    /** '처리자' 칸. 비밀번호 재설정은 로그인 전에 회원이 문자 인증으로 하므로 처리자가 비어 있다. */
    public function actorLabel(): string
    {
        if ($this->actor) {
            return $this->actor->company_name;
        }

        return $this->action === 'user.password.reset' ? '본인 (문자 인증)' : '시스템';
    }

    /** 이 기록을 남긴 운영자. 시스템 작업이면 null. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_idx');
    }

    /** 누가 무엇을 왜 했는지 한 줄 남긴다. */
    public static function record(string $action, string $targetType, ?int $targetIdx, ?string $reason = null): void
    {
        self::create([
            'actor_idx' => auth()->id(),
            'action' => $action,
            'target_type' => $targetType,
            'target_idx' => $targetIdx,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
