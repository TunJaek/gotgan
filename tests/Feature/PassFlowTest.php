<?php

namespace Tests\Feature;

use App\Support\Pass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 휴대폰 본인인증 경로(/pass/*). 다날 응답은 가짜로 만든다.
 */
class PassFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_다날_흐름_주문번호를_묶어_확인하고_결과를_세션에_둔다(): void
    {
        $this->useDanal('RETURNCODE=0000&USERID=01055556666&NAME='.urlencode('홍길동').'&CI=CI-XYZ');

        // 1. 시작: 다날에 ITEMSEND, 인증창으로 자동 이동하는 폼
        $this->post(route('pass.start', 'register'), ['phone' => '010-5555-6666'])
            ->assertOk()
            ->assertSee('https://wauth.teledit.com/Danal/WebAuth/Web/Start.php', false)
            ->assertSee('value="T123"', false)
            ->assertSee('name="IsDstAddr" value="010-5555-6666"', false);
        $order_id = session('pass.request.order_id');
        $this->assertStringStartsWith('GG', $order_id);

        // 2. 다날이 결과를 POST: 세션을 켜지 않고 곳간 안의 GET 으로 넘긴다
        $this->post(route('pass.callback'), ['TID' => 'T123'])
            ->assertRedirect(route('pass.complete', ['tid' => 'T123']))
            ->assertCookieMissing(config('session.cookie'));

        // 3. 확인: CONFIRMOPTION=1 + 세션의 주문번호로 다날에 묻는다
        $this->get(route('pass.complete', ['tid' => 'T123']))->assertRedirect(route('register'));

        Http::assertSent(function (Request $request) use ($order_id) {
            parse_str($request->body(), $d);

            return $d['TXTYPE'] === 'ITEMSEND'
                && $d['USERID'] === '01055556666' && $d['ORDERID'] === $order_id;
        });
        Http::assertSent(function (Request $request) use ($order_id) {
            parse_str($request->body(), $d);

            return $d['TXTYPE'] === 'CONFIRM' && $d['TID'] === 'T123'
                && $d['CONFIRMOPTION'] === '1' && $d['ORDERID'] === $order_id;
        });

        $result = Pass::result('register');
        $this->assertSame('01055556666', $result['phone']);
        $this->assertSame('홍길동', $result['name']);
        $this->assertSame(Pass::ciHash('CI-XYZ'), $result['ci_hash']);
        $this->assertNull(session('pass.request'));   // 한 요청으로 한 번만 확인한다
    }

    public function test_시작하지_않은_세션으로_온_결과는_받지_않는다(): void
    {
        $this->useDanal('RETURNCODE=0000&USERID=01055556666&NAME=x&CI=CI-XYZ');

        // 다른 곳(예: printingbox)에서 나온 TID 를 곳간 결과 주소에 바로 넣은 경우
        $this->get(route('pass.complete', ['tid' => 'STOLEN']))
            ->assertRedirect()
            ->assertSessionHas('notice');

        $this->assertNull(session('pass.result'));
        Http::assertNothingSent();   // 다날에 묻지도 않는다
    }

    public function test_응답에_번호나_c_i가_없으면_인증을_받아들이지_않는다(): void
    {
        $this->useDanal('RETURNCODE=0000&USERID=01011112222&NAME=x');

        $this->post(route('pass.start', 'reset'), ['phone' => '01011112222']);
        $this->get(route('pass.complete', ['tid' => 'T123']))
            ->assertRedirect(route('password.find'))
            ->assertSessionHas('notice');

        $this->assertNull(Pass::result('reset'));
    }

    public function test_개발용_가짜_인증(): void
    {
        $this->post(route('pass.start', 'register'), ['phone' => '010-2222-3333'])
            ->assertOk()->assertSee(route('pass.fake'), false);

        $this->post(route('pass.fake'), ['name' => '홍길동', 'ci' => 'FAKE-1'])
            ->assertRedirect(route('register'));

        $this->assertSame('01022223333', Pass::result('register')['phone']);
    }

    public function test_취소하면_원래_화면으로_안내와_함께_돌아간다(): void
    {
        $this->post(route('pass.start', 'reset'), ['phone' => '01012345678']);

        $this->get(route('pass.cancel'))->assertRedirect(route('pass.cancelled'));
        $this->get(route('pass.cancelled'))
            ->assertRedirect(route('password.find'))
            ->assertSessionHas('notice', '본인인증을 취소했습니다.');
    }

    public function test_휴대폰_변경_인증은_로그인해야_시작한다(): void
    {
        $this->post(route('pass.start', 'phone'), ['phone' => '01012345678'])->assertRedirect(route('login'));
    }

    public function test_세션에_묶은_번호와_다른_응답은_받아들이지_않는다(): void
    {
        $this->useDanal('RETURNCODE=0000&USERID=01099990000&NAME=x&CI=CI-XYZ');

        $this->post(route('pass.start', 'register'), ['phone' => '01011112222']);
        $this->get(route('pass.complete', ['tid' => 'T123']))
            ->assertRedirect(route('register'))
            ->assertSessionHas('notice');

        $this->assertNull(Pass::result('register'));
    }

    public function test_잘못된_휴대폰_번호로는_인증을_시작하지_않는다(): void
    {
        Http::fake();

        $this->post(route('pass.start', 'register'), ['phone' => '02-1234-5678'])
            ->assertRedirect(route('register'))
            ->assertSessionHas('notice', '휴대폰 번호를 다시 확인해 주세요.');

        $this->assertNull(session('pass.request'));
        Http::assertNothingSent();
    }

    /** 다날 드라이버로 바꾸고, ITEMSEND 와 CONFIRM 에 가짜 응답을 준다. */
    private function useDanal(string $confirm_response): void
    {
        config(['gotgan.pass.driver' => 'danal', 'gotgan.pass.cpid' => 'TESTCP', 'gotgan.pass.cppwd' => 'secret']);

        Http::fake(function (Request $request) use ($confirm_response) {
            parse_str($request->body(), $d);

            return $d['TXTYPE'] === 'ITEMSEND'
                ? Http::response('RETURNCODE=0000&TID=T123')
                : Http::response($confirm_response);
        });
    }
}
