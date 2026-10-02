<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 문자 발송. 프린팅박스와 같은 방식으로 동기 호출하고 결과를 그대로 돌려준다.
 * 개발 환경(SMS_DRIVER=log)에서는 실제로 보내지 않고 기록만 한다.
 */
class Sms
{
    /** @return array{success: bool, message_id: ?string, error: ?string} */
    public static function send(string $phone, string $body): array
    {
        return match (config('gotgan.sms.driver')) {
            'sens' => self::sendViaSens($phone, $body),
            'log' => self::logOnly($phone, $body),
            // 설정 오타가 조용히 성공으로 처리되지 않도록 한다.
            default => ['success' => false, 'message_id' => null,
                'error' => 'SMS_DRIVER 설정이 잘못되었습니다: '.config('gotgan.sms.driver')],
        };
    }

    private static function logOnly(string $phone, string $body): array
    {
        Log::channel('single')->info('[SMS:log] '.$phone.' | '.$body);

        return ['success' => true, 'message_id' => 'log-'.uniqid(), 'error' => null];
    }

    /** 네이버 클라우드 SENS. 90byte를 넘으면 LMS 로 보낸다. */
    private static function sendViaSens(string $phone, string $body): array
    {
        $serviceId = config('gotgan.sms.service_id');
        $accessKey = config('gotgan.sms.access_key');
        $secretKey = config('gotgan.sms.secret_key');
        $uri = "/sms/v2/services/{$serviceId}/messages";
        $timestamp = (string) (int) (microtime(true) * 1000);

        $signature = base64_encode(hash_hmac(
            'sha256',
            "POST {$uri}\n{$timestamp}\n{$accessKey}",
            (string) $secretKey,
            true
        ));

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'x-ncp-apigw-timestamp' => $timestamp,
                    'x-ncp-iam-access-key' => $accessKey,
                    'x-ncp-apigw-signature-v2' => $signature,
                ])
                ->post("https://sens.apigw.ntruss.com{$uri}", [
                    'type' => strlen($body) > 90 ? 'LMS' : 'SMS',
                    'from' => config('gotgan.sms.from'),
                    'content' => $body,
                    'messages' => [['to' => preg_replace('/\D/', '', $phone)]],
                ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }

        return $response->successful()
            ? ['success' => true, 'message_id' => $response->json('requestId'), 'error' => null]
            : ['success' => false, 'message_id' => null, 'error' => $response->body()];
    }
}
