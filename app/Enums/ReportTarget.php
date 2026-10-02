<?php

namespace App\Enums;

/**
 * reports.target_type — 무엇을 신고했나.
 *
 * 매물은 매물 상세의 '이 매물 신고하기', 회원은 대화 화면의 '이 회원 신고'로 접수한다.
 * 메시지(3)는 접수 경로가 없다. 괴롭힘은 메시지 한 건보다 대화 흐름을 봐야 해서
 * 회원 신고로 받고, 관리자가 신고 상세에서 두 사람의 대화를 함께 본다(2026-09-28).
 */
enum ReportTarget: int
{
    case Post = 1;
    case User = 2;
    case Message = 3;

    public function label(): string
    {
        return match ($this) {
            self::Post => '매물',
            self::User => '회원',
            self::Message => '메시지',
        };
    }

    /** audit_logs.target_type 에 넣는 이름. */
    public function auditType(): string
    {
        return match ($this) {
            self::Post => 'post',
            self::User => 'user',
            self::Message => 'message',
        };
    }
}
