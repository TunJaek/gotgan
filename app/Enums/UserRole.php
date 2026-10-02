<?php

namespace App\Enums;

/** users.role — DB 에는 숫자로 저장한다 */
enum UserRole: int
{
    case Member = 0;
    case Admin = 1;

    public function label(): string
    {
        return match ($this) {
            self::Member => '일반회원',
            self::Admin => '운영자',
        };
    }
}
