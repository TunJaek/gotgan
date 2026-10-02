<?php

namespace App\Enums;

/** existence_checks.result — 국세청 사업자 상태 조회의 원본 판정. */
enum ExistenceCheckResult: int
{
    case Failed = 0;
    case Active = 1;
    case Closed = 2;
    case Suspended = 3;
    case Unregistered = 4;

    public function label(): string
    {
        return match ($this) {
            self::Failed => '조회 실패',
            self::Active => '계속사업자',
            self::Closed => '폐업',
            self::Suspended => '휴업',
            self::Unregistered => '미등록',
        };
    }

    public function needsAttention(): bool
    {
        return $this !== self::Active;
    }
}
