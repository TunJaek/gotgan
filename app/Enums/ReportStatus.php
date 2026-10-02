<?php

namespace App\Enums;

/**
 * reports.status — 신고 처리 상태.
 *
 * 마이그레이션 주석에는 1=확인중 이 있지만 쓰지 않는다.
 * 관리자가 한 명이라 상세를 열 때마다 상태를 바꿀 실익이 없다.
 * 며칠씩 보류하는 일이 반복되면 그때 살린다.
 */
enum ReportStatus: int
{
    case Received = 0;
    case Resolved = 2;
    case Dismissed = 3;

    public function label(): string
    {
        return match ($this) {
            self::Received => '미처리',
            self::Resolved => '조치완료',
            self::Dismissed => '기각',
        };
    }

    /** 목록 탭에 쓰는 순서. */
    public static function tabs(): array
    {
        return [self::Received, self::Resolved, self::Dismissed];
    }
}
