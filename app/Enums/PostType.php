<?php

namespace App\Enums;

/** posts.post_type — 생성 후 변경할 수 없다 */
enum PostType: int
{
    case Sell = 1;
    case Want = 2;

    public function label(): string
    {
        return match ($this) {
            self::Sell => '팝니다',
            self::Want => '구해요',   // 메뉴·제목과 같은 이름(2026-09-29, 전에는 '삽니다')
        };
    }
}
