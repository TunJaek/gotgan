<?php

namespace App\Enums;

/**
 * businesses.existence_status — 국세청 조회로 판단한 사업자 존속 여부.
 * 폐업과 휴업은 게시물을 내리는 처리가 같아 Closed 하나로 합친다.
 */
enum ExistenceStatus: int
{
    case Unknown = 0;
    case Active = 1;
    case Closed = 2;

    public function label(): string
    {
        return match ($this) {
            self::Unknown => '미조회',
            self::Active => '계속사업자',
            self::Closed => '폐업·휴업',
        };
    }
}
