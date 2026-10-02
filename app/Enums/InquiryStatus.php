<?php

namespace App\Enums;

/** inquiries.status — 대화방이 아직 살아 있는지 */
enum InquiryStatus: int
{
    case Open = 0;
    case Closed = 1;

    public function label(): string
    {
        return match ($this) {
            self::Open => '진행중',
            self::Closed => '종료',
        };
    }
}
