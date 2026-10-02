<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 국세청 사업자 상태조회.
 * 가입할 때 한 번, 그 뒤로는 하루 1회 배치가 부른다.
 * 키가 없을 때(BIZNO_DRIVER=log)는 형식 검증만 하고 정상으로 취급한다.
 */
class BiznoStatus
{
    /** @return array{result: string, code: ?string, error: ?string} */
    public static function check(string $number): array
    {
        $n = BizNumber::normalize($number);

        if (config('gotgan.bizno.driver') === 'nts') {
            return self::checkViaNts($n);
        }

        if (config('gotgan.bizno.driver') !== 'log') {
            return ['result' => 'error', 'code' => null,
                'error' => 'BIZNO_DRIVER 설정이 잘못되었습니다: '.config('gotgan.bizno.driver')];
        }

        Log::channel('single')->info('[BIZNO:log] '.BizNumber::format($n));

        return [
            'result' => BizNumber::isValid($n) ? 'active' : 'not_found',
            'code' => 'log',
            'error' => null,
        ];
    }

    /** @return array{result: string, code: ?string, error: ?string} */
    private static function checkViaNts(string $n): array
    {
        $key = (string) config('gotgan.bizno.api_key');
        // 포털 키가 %2F·%3D 처럼 주소용으로 바뀐 형태면 한 번 풀어 둔다. %가 없으면 그대로 쓴다.
        $key = str_contains($key, '%') ? urldecode($key) : $key;

        if ($key === '') {
            return ['result' => 'error', 'code' => null, 'error' => 'BIZNO_API_KEY 가 비어 있습니다.'];
        }

        try {
            // 키는 포털 활용신청 상세의 '일반 인증키'를 그대로 넣는다. %가 섞인 형태면 위에서 풀어 두고, 주소용 인코딩은 여기서 다시 한다.
            $response = Http::timeout(10)
                ->withQueryParameters(['serviceKey' => $key])
                ->post('https://api.odcloud.kr/api/nts-businessman/v1/status', ['b_no' => [$n]]);
        } catch (\Throwable $e) {
            // 통신 실패가 곧 폐업은 아니다. 호출한 쪽에서 임시 조치만 하고 사람이 확인한다.
            // 오류 문구에 요청 주소가 섞이면 키가 같이 저장되므로 지운다.
            return ['result' => 'error', 'code' => null, 'error' => self::withoutKey($e->getMessage(), $key)];
        }

        if (! $response->successful()) {
            return ['result' => 'error', 'code' => null, 'error' => self::withoutKey($response->body(), $key)];
        }

        $data = $response->json('data.0');

        if (! $data || ($data['b_stt_cd'] ?? '') === '') {
            return ['result' => 'not_found', 'code' => null, 'error' => null];
        }

        return [
            'result' => match ($data['b_stt_cd']) {
                '01' => 'active',       // 계속사업자
                '02' => 'suspended',    // 휴업자
                '03' => 'closed',       // 폐업자
                default => 'error',
            },
            'code' => $data['b_stt_cd'],
            'error' => null,
        ];
    }

    /** 키가 원문 그대로든 주소용으로 인코딩됐든 가린다. */
    private static function withoutKey(string $text, string $key): string
    {
        return str_replace([$key, urlencode($key), rawurlencode($key)], '***', $text);
    }
}
