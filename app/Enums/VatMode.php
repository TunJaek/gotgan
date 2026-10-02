<?php

namespace App\Enums;

/** posts.vat_mode — 가격을 적은 글에만 있다 */
enum VatMode: int
{
    case Exclusive = 1;
    case Inclusive = 2;
    case Negotiable = 3;

    public function label(): string
    {
        return match ($this) {
            self::Exclusive => '부가세 별도',
            self::Inclusive => '부가세 포함',
            self::Negotiable => '협의',
        };
    }
}
