<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\PostStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ContactConsent;
use App\Models\Post;
use App\Models\User;
use App\Support\Pass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AccountEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_이메일_업종_지역을_바꾸고_상호는_바꿀_수_없다(): void
    {
        $user = $this->unlockedUser();

        Livewire::actingAs($user)->test('account.edit')
            ->assertSee('사업자등록증과 같아야 해서 여기서 바꿀 수 없습니다')
            ->set('email', 'new@company.co.kr')
            ->set('industry', '포장재 도매')
            ->set('region_code', '11680101')->set('region_name', '서울 강남구 역삼동')
            ->call('saveBasic')
            ->assertHasNoErrors()
            ->assertSet('basic_notice', '저장했습니다.');

        $fresh = $user->fresh();
        $this->assertSame('new@company.co.kr', $fresh->email);
        $this->assertSame('서울 강남구', $fresh->regionName());
        $this->assertSame('대성포장산업', $fresh->company_name);   // 그대로
    }

    public function test_이메일은_점_있는_도메인만_받는다(): void
    {
        $user = $this->unlockedUser();
        $c = Livewire::actingAs($user)->test('account.edit')->assertSee('이메일 도메인 선택');

        foreach (['1123123@xn--3pd7plb0a7646st4e1yx', 'abc@naver', 'abc@gmail.c', 'abc@'] as $bad) {
            $c->set('email', $bad)->call('saveBasic')->assertHasErrors('email');
        }
        foreach (['abc@naver.com', 'abc@company.co.kr', '', 'abc@한글.kr'] as $good) {
            $c->set('email', $good)->call('saveBasic')->assertHasNoErrors('email');
        }
        $this->assertSame('abc@한글.kr', $user->fresh()->email);
    }

    public function test_본인인증_없이는_휴대폰을_바꿀_수_없다(): void
    {
        $user = $this->unlockedUser();

        Livewire::actingAs($user)->test('account.edit')
            ->assertSee('휴대폰 본인인증으로 번호 바꾸기')
            ->call('changePhone')
            ->assertHasErrors('phone');

        $this->assertSame('01012345678', $user->fresh()->phone);
    }

    public function test_다른_사람이_인증하면_휴대폰을_바꿀_수_없다(): void
    {
        $user = $this->unlockedUser();
        Pass::remember('phone', '010-7777-8888', '김철수', 'CI-KIM');

        Livewire::actingAs($user)->test('account.edit')
            ->call('changePhone')
            ->assertHasErrors('phone');

        $this->assertSame('01012345678', $user->fresh()->phone);
    }

    public function test_같은_사람이_인증하면_번호가_바뀌고_공개하던_매물이_비공개가_된다(): void
    {
        $user = $this->unlockedUser();
        $post = Post::create([
            'user_idx' => $user->idx, 'post_type' => 1, 'category_id' => 15, 'title' => '공개 매물',
            'description' => '설명', 'condition' => 1, 'status' => PostStatus::Selling, 'is_hidden' => false,
            'region_code' => '11590102', 'region_name' => '서울 동작구 상도동', 'show_phone' => true, 'photos' => [],
        ]);
        ContactConsent::syncFor($post);

        Pass::remember('phone', '010-7777-8888', '홍길동', 'CI-HONG');

        Livewire::actingAs($user)->test('account.edit')
            ->assertSee('010-7777-8888')          // 인증한 새 번호가 보인다
            ->call('changePhone')
            ->assertHasNoErrors();

        $this->assertSame('01077778888', $user->fresh()->phone);
        $this->assertFalse($post->fresh()->show_phone);
        $this->assertNotNull(ContactConsent::first()->withdrawn_at);
        $this->assertSame(1, AuditLog::where('action', 'user.phone.changed')->count());
        $this->assertNull(Pass::result('phone'));   // 쓴 인증은 지운다
    }

    public function test_현재_비밀번호가_맞아야_바뀌고_다른_기기는_로그아웃된다(): void
    {
        $user = $this->unlockedUser();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->idx, 'payload' => '', 'last_activity' => time()]);

        Livewire::actingAs($user)->test('account.edit')
            ->set('current_password', 'Wrong123!')
            ->set('password', 'NewPass9!')
            ->set('password_confirmation', 'NewPass9!')
            ->call('changePassword')
            ->assertHasErrors('current_password');
        $this->assertTrue(Auth::validate(['idx' => $user->idx, 'password' => 'Abcd123!']));

        Livewire::actingAs($user)->test('account.edit')
            ->set('current_password', 'Abcd123!')
            ->set('password', 'NewPass9!')
            ->set('password_confirmation', 'NewPass9!')
            ->call('changePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Auth::validate(['idx' => $user->idx, 'password' => 'NewPass9!']));
        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device')->count());
        $this->assertSame(1, AuditLog::where('action', 'user.password.changed')->count());
    }

    public function test_본인인증_전에는_수정칸이_보이지_않고_저장도_막힌다(): void
    {
        $user = $this->approvedUser();

        Livewire::actingAs($user)->test('account.edit')
            ->assertSee('본인 확인')
            ->assertDontSee('비밀번호 변경')
            ->set('email', 'hack@company.co.kr')
            ->call('saveBasic')
            ->assertRedirect(route('account.edit'));

        $this->assertNull($user->fresh()->email);
    }

    public function test_입장_인증은_입력값이_아니라_가입한_번호로_시작한다(): void
    {
        $user = $this->approvedUser();

        $this->actingAs($user)->post(route('pass.start', 'edit'), ['phone' => '010-9999-9999'])->assertOk();

        $this->assertSame('01012345678', session('pass.request.phone'));
    }

    public function test_가입한_본인이_인증하면_30분_동안_수정할_수_있다(): void
    {
        $user = $this->approvedUser();
        Pass::remember('edit', '010-1234-5678', '홍길동', 'CI-HONG');

        Livewire::actingAs($user)->test('account.edit')
            ->assertSee('비밀번호 변경')
            ->set('industry', '포장재 도매')
            ->call('saveBasic')
            ->assertSet('basic_notice', '저장했습니다.');
        $this->assertNull(Pass::result('edit'));   // 입장 인증은 한 번만 쓴다

        $this->travel(31)->minutes();
        Livewire::actingAs($user)->test('account.edit')
            ->assertSee('본인 확인')
            ->call('saveBasic')
            ->assertRedirect(route('account.edit'));
    }

    public function test_다른_사람이나_다른_번호로_인증하면_고객센터로_안내하고_내보낸다(): void
    {
        $user = $this->approvedUser();

        Pass::remember('edit', '010-1234-5678', '김철수', 'CI-KIM');      // 번호는 같고 사람이 다름
        Livewire::actingAs($user)->test('account.edit')->assertRedirect(route('account.index'));
        $this->assertStringContainsString('고객센터', session('notice'));

        Pass::remember('edit', '010-7777-8888', '홍길동', 'CI-HONG');     // 사람은 같고 번호가 다름
        Livewire::actingAs($user)->test('account.edit')->assertRedirect(route('account.index'));
        $this->assertNull(session('account.unlocked'));
    }

    public function test_CI가_없는_옛_계정은_가입_번호로_인증하면_CI를_묶는다(): void
    {
        $user = $this->approvedUser();
        $user->update(['ci_hash' => null]);

        Pass::remember('edit', '010-1234-5678', '홍길동', 'CI-HONG');
        Livewire::actingAs($user)->test('account.edit')->assertNoRedirect()->assertSee('비밀번호 변경');

        $this->assertSame(Pass::ciHash('CI-HONG'), $user->fresh()->ci_hash);
    }

    private function unlockedUser(): User
    {
        $user = $this->approvedUser();
        session()->put('account.unlocked', ['idx' => $user->idx, 'at' => now()->timestamp]);

        return $user;
    }

    private function approvedUser(): User
    {
        $user = new User([
            'password' => 'Abcd123!', 'phone' => '01012345678', 'phone_verified_at' => now(),
            'ci_hash' => Pass::ciHash('CI-HONG'),
            'name' => '홍길동', 'company_name' => '대성포장산업', 'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => UserRole::Member, 'status' => UserStatus::Active, 'review_status' => ApplicationStatus::Approved,
            'submitted_at' => now(), 'approved_at' => now(),
            'existence_status' => ExistenceStatus::Active, 'existence_checked_at' => now(),
        ]);
        $user->biz_no = '123-45-67891';
        $user->save();

        return $user->fresh();
    }
}
