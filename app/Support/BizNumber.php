<?php

namespace App\Support;

/**
 * 사업자등록번호 10자리 처리.
 * users.biz_no 에 하이픈 없이 그대로 저장한다(고유식별정보가 아니라 암호화 대상이 아니다).
 */
class BizNumber
{
    /** 입력에서 숫자만 남긴다. */
    public static function normalize(string $input): string
    {
        return preg_replace('/\D/', '', $input) ?? '';
    }

    /** 123-45-67890 형태로 보여준다. */
    public static function format(string $number): string
    {
        $n = self::normalize($number);

        return strlen($n) === 10
            ? substr($n, 0, 3).'-'.substr($n, 3, 2).'-'.substr($n, 5)
            : $number;
    }

    /**
     * 국세청 검증 규칙(가중치 1,3,7,1,3,7,1,3,5).
     * 9번째 자리는 곱한 뒤 10으로 나눈 몫을 더한다.
     */
    public static function isValid(string $input): bool
    {
        $n = self::normalize($input);

        if (strlen($n) !== 10) {
            return false;
        }

        $weights = [1, 3, 7, 1, 3, 7, 1, 3, 5];
        $sum = 0;

        foreach ($weights as $i => $w) {
            $sum += ((int) $n[$i]) * $w;
        }

        $sum += intdiv(((int) $n[8]) * 5, 10);

        return (10 - $sum % 10) % 10 === (int) $n[9];
    }
}
