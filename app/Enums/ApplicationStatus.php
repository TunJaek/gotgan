<?php

namespace App\Enums;

/** applications.status — 숫자는 ReviewDecision 과 일부러 맞춰 놓았다 */
enum ApplicationStatus: int
{
    case Draft = 0;
    case Reviewing = 1;
    case Supplement = 2;
    case Approved = 3;
    case Rejected = 4;

    public function label(): string
    {
        return match ($this) {
            self::Draft => '작성중',
            self::Reviewing => '심사중',
            self::Supplement => '보완요청',
            self::Approved => '승인',
            self::Rejected => '반려',
        };
    }

    /** 이미 끝난 신청인가. 끝난 건은 다시 심사하지 않는다. */
    public function isFinal(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }
}
