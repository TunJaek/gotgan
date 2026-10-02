<?php

namespace App\Enums;

/** posts.status — 판매자가 직접 바꾼다. 운영자 숨김(is_hidden)과는 별개다. */
enum PostStatus: int
{
    case Draft = 0;
    case Selling = 1;
    case Done = 2;
    case Stopped = 3;
    case Archived = 4;

    /** 같은 상태라도 구해요 글에서는 다르게 부른다. 타입을 안 주면 팝니다 기준. */
    public function label(?PostType $type = null): string
    {
        if ($type === PostType::Want) {
            return match ($this) {
                self::Draft => '작성중',
                self::Selling => '모집중',
                self::Done => '구매완료',
                self::Stopped => '모집중지',
                self::Archived => '보관',
            };
        }

        return match ($this) {
            self::Draft => '작성중',
            self::Selling => '판매중',
            self::Done => '거래완료',
            self::Stopped => '판매중지',
            self::Archived => '보관',
        };
    }
}
