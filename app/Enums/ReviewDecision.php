<?php

namespace App\Enums;

/**
 * application_reviews.decision — 운영자가 내린 심사 결정.
 * 숫자를 ApplicationStatus 와 똑같이 맞춰서 변환이 단순해진다.
 */
enum ReviewDecision: int
{
    case Supplement = 2;
    case Approved = 3;
    case Rejected = 4;

    public function label(): string
    {
        return match ($this) {
            self::Supplement => '자료보완',
            self::Approved => '승인',
            self::Rejected => '반려',
        };
    }

    /** 사유 입력이 필수인가. 승인만 사유 없이 처리할 수 있다. */
    public function requiresReason(): bool
    {
        return $this !== self::Approved;
    }

    /** 이 결정이 신청을 어떤 상태로 바꾸는가. */
    public function toApplicationStatus(): ApplicationStatus
    {
        return ApplicationStatus::from($this->value);
    }
}
