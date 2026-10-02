<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 휴대폰 본인인증(다날 PASS). 가입·비밀번호 찾기·정보 수정 입장·휴대폰 변경이 같이 쓴다.
 *
 *   start()   → 다날 인증창으로 보낼 값을 만든다. 입력 번호·주문번호를 세션에 남긴다
 *   confirm() → 다날이 돌려준 TID 를 '그 주문번호로 시작한 인증이 맞는지' 다날에 확인한다
 *   result()  → 확인된 결과를 꺼낸다(10분, 목적이 같을 때만). consume 하면 한 번만 쓴다
 *
 * printingbox 다날 코드(helpers/danal.php)와 흐름은 같지만 세 가지를 바로잡았다(2026-09-28).
 *   · TLS 인증서 검증을 끄지 않는다(Http 기본값)
 *   · 주문번호를 고정 글자가 아니라 요청마다 새로 만들고 세션에 묶는다
 *   · CONFIRMOPTION=1 — 다날이 CPID·주문번호까지 맞는지 확인한다
 *     같은 CPID 를 printingbox 와 함께 쓰므로, 이게 없으면 printingbox 인증 결과를 곳간에 붙여 넣을 수 있다.
 *
 * 가맹점 ID·비밀번호는 .env 에만 둔다(DANAL_CPID, DANAL_CPPWD).
 * PASS_DRIVER=log 이면 다날을 부르지 않고 가짜 인증 화면(/pass/fake)으로 보낸다(개발용).
 */
class Pass
{
    public const PURPOSES = ['register', 'reset', 'edit', 'phone'];

    /** 인증 요청·결과가 살아 있는 시간(분) */
    public const TTL = 10;

    private const READY_URL = 'https://uas.teledit.com/uas/';

    private const AUTH_URL = 'https://wauth.teledit.com/Danal/WebAuth/Web/Start.php';

    /**
     * 인증을 시작한다. 성공하면 브라우저가 자동으로 보낼 폼(주소와 값)을 돌려준다.
     *
     * @return array{ok: bool, action?: string, fields?: array<string, string>, error?: string}
     */
    public static function start(string $purpose, string $phone): array
    {
        abort_unless(in_array($purpose, self::PURPOSES, true), 404);

        $phone = self::normalizePhone($phone);

        if (! preg_match('/^01[016789]\d{7,8}$/', $phone)) {
            return ['ok' => false, 'error' => '휴대폰 번호를 다시 확인해 주세요.'];
        }

        if (config('gotgan.pass.driver') === 'danal'
            && (! config('gotgan.pass.cpid') || ! config('gotgan.pass.cppwd'))) {
            Log::error('[pass] DANAL_CPID 또는 DANAL_CPPWD 설정이 없습니다.');

            return ['ok' => false, 'error' => '본인인증 설정을 확인하는 중입니다. 운영자에게 알려 주세요.'];
        }

        $order_id = 'GG'.now()->format('ymdHis').Str::upper(Str::random(10));
        session()->put('pass.request', [
            'purpose' => $purpose,
            'phone' => $phone,
            'order_id' => $order_id,
            'at' => now()->timestamp,
        ]);
        session()->forget('pass.result');

        if (config('gotgan.pass.driver') === 'log') {
            return ['ok' => true, 'action' => route('pass.fake'), 'fields' => []];
        }

        $res = self::call([
            'TXTYPE' => 'ITEMSEND',
            'SERVICE' => 'UAS',
            'AUTHTYPE' => '36',
            'CPID' => config('gotgan.pass.cpid'),
            'CPPWD' => config('gotgan.pass.cppwd'),
            'TARGETURL' => route('pass.callback'),
            'CPTITLE' => '곳간',
            // USERID 는 CONFIRM 응답에 돌아온다. 임의 값이 아니라 인증창에 고정할 번호와 같게 묶는다.
            'USERID' => $phone,
            'ORDERID' => $order_id,
        ]);

        if (($res['RETURNCODE'] ?? '') !== '0000') {
            return ['ok' => false, 'error' => $res['RETURNMSG'] ?? '본인인증을 시작하지 못했습니다.'];
        }

        // 다날 인증창으로 넘길 값: 다날이 준 값(RETURNCODE·MSG 제외) + 취소 시 돌아올 곳
        $fields = collect($res)->except(['RETURNCODE', 'RETURNMSG'])->all();
        $fields['BackURL'] = route('pass.cancel');
        $fields['IsCharSet'] = 'UTF-8';
        $fields['IsDstAddr'] = self::formatPhone($phone);

        return ['ok' => true, 'action' => self::AUTH_URL, 'fields' => $fields];
    }

    /**
     * 다날이 돌려준 TID 를 확인한다. 성공하면 결과를 세션에 넣고 목적(purpose)을 돌려준다.
     *
     * @return array{ok: bool, purpose?: string, error?: string}
     */
    public static function confirm(string $tid): array
    {
        $request = self::freshRequest();

        if (! $request) {
            return ['ok' => false, 'error' => '본인인증 요청이 없거나 10분이 지났습니다. 처음부터 다시 진행해 주세요.'];
        }

        // 한 요청으로 한 번만 확인한다
        session()->forget('pass.request');

        $res = self::call([
            'TXTYPE' => 'CONFIRM',
            'TID' => $tid,
            'CONFIRMOPTION' => '1',               // 다날이 CPID·주문번호까지 맞는지 본다
            'IDENOPTION' => '1',
            'CPID' => config('gotgan.pass.cpid'),
            'ORDERID' => $request['order_id'],
        ]);

        $fields = config('gotgan.pass.fields');
        $phone = preg_replace('/\D/', '', (string) ($res[$fields['phone']] ?? ''));
        $ci = (string) ($res[$fields['ci']] ?? '');

        if (($res['RETURNCODE'] ?? '') !== '0000') {
            return ['ok' => false, 'purpose' => $request['purpose'], 'error' => '본인인증에 실패했습니다: '.($res['RETURNMSG'] ?? '알 수 없는 오류')];
        }

        // USERID 는 다날 요청에 넣은 번호이자 인증창에 IsDstAddr 로 고정한 번호다.
        // 응답이 세션에 묶은 번호와 다르거나 CI 가 없으면 받아들이지 않는다.
        if ($phone === '' || ! hash_equals($request['phone'], $phone) || $ci === '') {
            Log::warning('[pass] 응답에 번호나 CI 가 없습니다. config gotgan.pass.fields 를 확인하세요. 받은 항목: '.implode(',', array_keys($res)));

            return ['ok' => false, 'purpose' => $request['purpose'], 'error' => '본인인증 결과를 읽지 못했습니다. 운영자에게 알려 주세요.'];
        }

        self::remember($request['purpose'], $phone, (string) ($res[$fields['name']] ?? ''), $ci);

        return ['ok' => true, 'purpose' => $request['purpose']];
    }

    /** 확인된 결과를 세션에 둔다. 개발용 가짜 인증과 테스트도 이걸 쓴다. CI 는 해시로만 둔다. */
    public static function remember(string $purpose, string $phone, string $name, string $ci): void
    {
        session()->put('pass.result', [
            'purpose' => $purpose,
            'phone' => self::normalizePhone($phone),
            'name' => $name,
            'ci_hash' => self::ciHash($ci),
            'at' => now()->timestamp,
        ]);
    }

    /**
     * 목적이 같고 10분 안의 결과만 돌려준다. consume 이면 꺼내면서 지운다(한 번만 쓰게).
     *
     * @return array{purpose: string, phone: string, name: string, ci_hash: string, at: int}|null
     */
    public static function result(string $purpose, bool $consume = false, int $minutes = self::TTL): ?array
    {
        $result = session('pass.result');

        if (! $result || $result['purpose'] !== $purpose || $result['at'] < now()->subMinutes($minutes)->timestamp) {
            return null;
        }

        if ($consume) {
            session()->forget('pass.result');
        }

        return $result;
    }

    /**
     * 인증을 마치고 막 돌아온 첫 화면에서만 결과를 살려 둔다.
     * 다른 곳에 갔다가 그 화면을 새로 열면(새로고침 포함) 결과를 지워서 다시 인증하게 한다(2026-09-29, 가입 화면).
     * 화면을 새로 열 때(mount) 부른다. 같은 화면 안의 입력·버튼 요청은 mount 를 다시 거치지 않는다.
     */
    public static function keepOnlyOnReturn(string $purpose): void
    {
        $result = session('pass.result');

        if (! $result || $result['purpose'] !== $purpose) {
            return;
        }

        if (! empty($result['shown'])) {
            session()->forget('pass.result');

            return;
        }

        session()->put('pass.result.shown', true);
    }

    /** 인증을 마치거나 취소한 뒤 돌아갈 화면 */
    public static function returnUrl(string $purpose): string
    {
        return match ($purpose) {
            'register' => route('register'),
            'reset' => route('password.find'),
            'edit', 'phone' => route('account.edit'),
            default => route('market.index'),
        };
    }

    /**
     * CI 를 되돌릴 수 없는 값으로. 원본 CI 는 어디에도 저장하지 않는다.
     * APP_KEY 로 만들므로 APP_KEY 를 바꾸면 기존 회원의 CI 해시와 맞지 않게 된다. 운영 서버에서 APP_KEY 를 바꾸지 말 것.
     */
    public static function ciHash(string $ci): string
    {
        return hash_hmac('sha256', $ci, (string) config('app.key'));
    }

    public static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }

    private static function freshRequest(): ?array
    {
        $request = session('pass.request');

        return $request && $request['at'] >= now()->subMinutes(self::TTL)->timestamp ? $request : null;
    }

    private static function formatPhone(string $phone): string
    {
        return preg_replace('/^(\d{3})(\d{3,4})(\d{4})$/', '$1-$2-$3', $phone) ?? $phone;
    }

    /** 다날 서버 호출. 요청·응답 모두 key=value&… 형식이다. TLS 검증은 Http 기본값(켜짐) 그대로. */
    private static function call(array $data): array
    {
        try {
            $body = Http::timeout(30)->connectTimeout(5)
                ->withBody(http_build_query($data), 'application/x-www-form-urlencoded; charset=UTF-8')
                ->post(self::READY_URL)
                ->body();
        } catch (\Throwable $e) {
            return ['RETURNCODE' => '-1', 'RETURNMSG' => '다날 서버에 연결하지 못했습니다.'];
        }

        parse_str($body, $res);

        // 다날은 한글(이름·메시지)을 EUC-KR 로 줄 때가 있다. 값을 풀어낸 뒤에 바꿔야 한다.
        return array_map(
            fn ($v) => is_string($v) && ! mb_check_encoding($v, 'UTF-8') ? mb_convert_encoding($v, 'UTF-8', 'EUC-KR') : $v,
            $res
        );
    }
}
