<?php

namespace App\Enums;

/** users.status */
enum UserStatus: int
{
    case Active = 0;
    case Suspended = 1;
    case Withdrawn = 2;

    public function label(): string
    {
        return match ($this) {
            self::Active => '정상',
            self::Suspended => '이용정지',
            self::Withdrawn => '탈퇴',
        };
    }
}
