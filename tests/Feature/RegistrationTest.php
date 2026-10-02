<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\Agreement;
use App\Models\User;
use App\Support\BizNumber;
use App\Support\Pass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_BIZ = '123-45-67891';   // 검증 규칙을 통과하는 번호
    private const INVALID_BIZ = '123-45-67890';

    public function test_사업자등록번호_검증규칙(): void
    {
        $this->assertTrue(BizNumber::isValid(self::VALID_BIZ));
        $this->assertFalse(BizNumber::isValid(self::INVALID_BIZ));
        $this->assertFalse(BizNumber::isValid('12345'));
        $this->assertSame('123-45-67891', BizNumber::format('1234567891'));
        // 표기가 달라도 같은 값으로 저장돼야 로그인이 된다
        $this->assertSame(BizNumber::normalize('1234567891'), BizNumber::normalize('123-45-67891'));
    }

    public function test_가입하면_심사중_상태로_접수된다(): void
    {
        Storage::fake('local');

        $this->register()->assertRedirect(route('register.complete'));

        $user = User::first();
        $this->assertSame('대성포장산업', $user->company_name);
        $this->assertSame(ApplicationStatus::Reviewing, $user->review_status);
        $this->assertSame(self::VALID_BIZ, $user->bizNo());
        $this->assertSame(BizNumber::normalize(self::VALID_BIZ), $user->biz_no);
        $this->assertNotNull($user->submitted_at);
        $this->assertNotNull($user->phone_verified_at);

        // 번호는 본인인증 결과에서 가져오고, CI 는 해시로만 남는다
        $this->assertSame('01012345678', $user->phone);
        $this->assertSame(Pass::ciHash('CI-HONG'), $user->ci_hash);
        $this->assertNotNull($user->identity_verified_at);
        $this->assertNull(Pass::result('register'));   // 한 번 쓴 인증은 지운다
    }

    public function test_같은_사업자번호로는_두_번_가입할_수_없다(): void
    {
        Storage::fake('local');
        $this->register();

        auth()->logout();
        $this->register()->assertRedirect(route('register.duplicate'));

        $this->assertSame(1, User::count());
    }

    public function test_중복_확인_버튼은_그_자리에서_알려준다(): void
    {
        Storage::fake('local');
        $this->register();                                          // VALID_BIZ 는 이미 가입됨
        auth()->logout();
        Pass::remember('register', '010-5555-6666', '김철수', 'CI-KIM');

        Livewire::test('auth.register')
            ->set('bizNumber', self::VALID_BIZ)
            ->call('checkBizNumber')
            ->assertHasErrors('bizNumber')
            ->assertSee('이미 가입된 사업자등록번호입니다')
            ->set('bizNumber', self::INVALID_BIZ)
            ->call('checkBizNumber')
            ->assertSee('사업자등록번호 10자리를 다시 확인해 주세요')
            ->set('bizNumber', '220-81-62517')
            ->call('checkBizNumber')
            ->assertHasNoErrors()
            ->assertSee('가입할 수 있는 사업자등록번호입니다')
            ->set('bizNumber', '220-81-62518')                     // 번호를 고치면 확인이 풀린다
            ->assertSet('checkedBiz', null)
            ->assertDontSee('가입할 수 있는 사업자등록번호입니다');
    }

    public function test_중복_확인은_본인인증한_사람만_10분에_10번까지(): void
    {
        Livewire::test('auth.register')
            ->set('bizNumber', '220-81-62517')
            ->call('checkBizNumber')
            ->assertHasErrors('pass');                              // 인증 없이는 누가 회원인지 물어볼 수 없다

        Pass::remember('register', '010-5555-6666', '김철수', 'CI-KIM');
        $c = Livewire::test('auth.register')->set('bizNumber', '220-81-62517');
        foreach (range(1, 10) as $i) {
            $c->call('checkBizNumber')->assertHasNoErrors();
        }
        $c->call('checkBizNumber')->assertHasErrors('bizNumber')->assertSee('초 뒤에 다시 확인해 주세요');
    }

    public function test_가입_화면을_나갔다가_다시_들어오면_본인인증을_다시_한다(): void
    {
        Pass::remember('register', '010-5555-6666', '김철수', 'CI-KIM');   // 인증을 마치고 돌아온다

        $this->get(route('register'))->assertSee('아이디 (사업자등록번호)');   // 돌아온 첫 화면: 양식이 보인다
        $this->get(route('market.index'));
        $this->get(route('register'))->assertSee('1. 휴대폰 본인인증');      // 다시 들어오면 처음부터
        $this->assertNull(Pass::result('register', minutes: 30));
    }

    public function test_본인인증을_안하면_가입되지_않는다(): void
    {
        Storage::fake('local');

        Livewire::test('auth.register')
            ->assertSee('휴대폰 본인인증')          // 양식 대신 인증 버튼이 보인다
            ->assertDontSee('가입 신청')
            ->set('bizNumber', self::VALID_BIZ)     // 화면을 거치지 않고 바로 보내도
            ->set('password', 'Abcd123!')
            ->set('passwordConfirmation', 'Abcd123!')
            ->set('companyName', '대성포장산업')
            ->set('ownerName', '홍길동')
            ->set('region_code', '11590102')->set('region_name', '서울 동작구 상도동')
            ->set('license', UploadedFile::fake()->image('license.jpg'))
            ->set('agreeTerms', true)
            ->set('agreePrivacy', true)
            ->call('submit')
            ->assertHasErrors('pass');

        $this->assertSame(0, User::count());
    }

    public function test_다른_용도의_본인인증으로는_가입되지_않는다(): void
    {
        Storage::fake('local');
        Pass::remember('reset', '010-1234-5678', '홍길동', 'CI-HONG');   // 비밀번호 찾기용 인증

        Livewire::test('auth.register')
            ->set('bizNumber', self::VALID_BIZ)
            ->set('password', 'Abcd123!')
            ->set('passwordConfirmation', 'Abcd123!')
            ->set('companyName', '대성포장산업')
            ->set('ownerName', '홍길동')
            ->set('region_code', '11590102')->set('region_name', '서울 동작구 상도동')
            ->set('license', UploadedFile::fake()->image('license.jpg'))
            ->set('agreeTerms', true)
            ->set('agreePrivacy', true)
            ->call('submit')
            ->assertHasErrors('pass');

        $this->assertSame(0, User::count());
    }

    public function test_비밀번호_규칙(): void
    {
        foreach (['abcd1234!', 'ABCD1234!', 'Abcdefgh', 'Ab1!', 'Abcd1234!'.str_repeat('a', 56)] as $bad) {  // 마지막은 65자 — 상한 64자 초과
            Livewire::test('auth.register')
                ->set('password', $bad)
                ->set('passwordConfirmation', $bad)
                ->call('submit')
                ->assertHasErrors('password');
        }
    }

    public function test_승인_전에는_등록_화면에_들어갈_수_없다(): void
    {
        Storage::fake('local');
        $this->register();

        $this->get(route('posts.create'))->assertRedirect(route('account.index'));

        User::first()->update(['review_status' => ApplicationStatus::Approved, 'approved_at' => now()]);

        // 승인 뒤에는 새 요청이 DB에서 다시 읽는다
        $this->actingAs(User::first()->fresh());

        $this->get(route('posts.create'))->assertOk();
    }

    public function test_로그인은_사업자번호와_비밀번호로_한다(): void
    {
        Storage::fake('local');
        $this->register();
        auth()->logout();

        Livewire::test('auth.login')
            ->set('bizNumber', self::VALID_BIZ)
            ->set('password', '틀린비밀번호')
            ->call('submit')
            ->assertHasErrors('bizNumber');
        $this->assertGuest();

        Livewire::test('auth.login')
            ->set('bizNumber', '1234567891')       // 하이픈 없이 입력해도 된다
            ->set('password', 'Abcd123!')
            ->call('submit')
            ->assertRedirect(route('market.index'));
        $this->assertAuthenticated();
    }

    /** 인증번호까지 통과한 정상 가입 한 건 */
    public function test_약관과_처리방침은_로그인_없이_볼_수_있다(): void
    {
        $this->get(route('terms'))->assertOk()->assertSee('곳간 이용약관');
        $this->get(route('privacy'))->assertOk()->assertSee('곳간 개인정보처리방침')->assertSee('<table>', false);
    }

    public function test_가입하면_약관과_처리방침_동의가_판_번호와_함께_남는다(): void
    {
        Storage::fake('local');
        $this->register()->assertHasNoErrors();

        $user = User::first();
        $this->assertSame(
            ['privacy' => 1, 'terms' => 1],
            Agreement::where('user_idx', $user->idx)->orderBy('document')->pluck('version', 'document')->all()
        );
    }

    private function register()
    {
        // 다날 인증창을 거쳐 돌아온 상태(개발용 가짜 인증과 같은 경로)
        Pass::remember('register', '010-1234-5678', '홍길동', 'CI-HONG');

        return Livewire::test('auth.register')
            ->set('bizNumber', self::VALID_BIZ)
            ->set('password', 'Abcd123!')
            ->set('passwordConfirmation', 'Abcd123!')
            ->set('companyName', '대성포장산업')
            ->set('ownerName', '홍길동')
            ->set('email', 'owner@company.co.kr')
            ->set('region_code', '11590102')->set('region_name', '서울 동작구 상도동')
            ->set('license', UploadedFile::fake()->image('license.jpg'))
            ->set('agreeTerms', true)
            ->set('agreePrivacy', true)
            ->call('submit');
    }
}
