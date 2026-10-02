<?php

namespace App\Enums;

/** posts.condition — 비워둘 수 있다(WANT 글은 해당 없음) */
enum PostCondition: int
{
    case New = 1;
    case Unopened = 2;
    case Used = 3;
    case Defect = 4;

    public function label(): string
    {
        return match ($this) {
            self::New => '미사용',   // '새것'은 목록의 '새 글' 배지와 헷갈려 바꿨다(2026-09-30)
            self::Unopened => '미개봉',
            self::Used => '중고',
            self::Defect => '하자',
        };
    }
}
