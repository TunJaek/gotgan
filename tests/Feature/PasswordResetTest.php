<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Pass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_가입한_사람과_같은_사람이면_바뀌고_다른_로그인은_끊긴다(): void
    {
        $user = $this->approvedUser(ci: 'CI-HONG');
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->idx, 'payload' => '', 'last_activity' => time()]);

        // 번호가 바뀌었어도 같은 사람(CI)이면 찾을 수 있다
        Pass::remember('reset', '010-9999-0000', '홍길동', 'CI-HONG');

        $this->reset('123-45-67891', 'NewPass1!')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Auth::validate(['idx' => $user->idx, 'password' => 'NewPass1!']));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->idx)->count());
        $this->assertSame(1, AuditLog::where('action', 'user.password.reset')->count());

        // 같은 인증으로 한 번 더 바꿀 수 없다
        $this->reset('123-45-67891', 'Another1!')->assertHasErrors('biz_number');
        $this->assertTrue(Auth::validate(['idx' => $user->idx, 'password' => 'NewPass1!']));
    }

    public function test_다른_사람이_인증하면_번호가_같아도_바꿀_수_없다(): void
    {
        $user = $this->approvedUser(ci: 'CI-HONG');
        Pass::remember('reset', '010-1234-5678', '김철수', 'CI-KIM');   // 번호는 같고 사람이 다르다

        $this->reset('123-45-67891', 'NewPass1!')
            ->assertHasErrors('biz_number')
            ->assertSee('사업자등록번호와 본인인증 정보가 맞지 않습니다.', false);

        $this->assertTrue(Auth::validate(['idx' => $user->idx, 'password' => 'Abcd123!']));
    }

    public function test_CI가_없는_계정은_번호가_같아도_찾을_수_없다(): void
    {
        $user = $this->approvedUser(ci: null);   // 가입 때 본인인증을 하지 않은 계정
        Pass::remember('reset', '010-1234-5678', '홍길동', 'CI-HONG');   // 번호는 같다

        $this->reset('123-45-67891', 'NewPass1!')->assertHasErrors('biz_number');

        $this->assertTrue(Auth::validate(['idx' => $user->idx, 'password' => 'Abcd123!']));
        $this->assertNull($user->fresh()->ci_hash);   // 번호만으로 CI 를 묶지 않는다
    }

    public function test_본인인증_없이는_바꿀_수_없다(): void
    {
        $user = $this->approvedUser(ci: 'CI-HONG');

        Livewire::test('auth.reset')->assertSee('휴대폰 본인인증');   // 양식 대신 인증 버튼

        $this->reset('123-45-67891', 'NewPass1!')->assertHasErrors('biz_number');
        $this->assertTrue(Auth::validate(['idx' => $user->idx, 'password' => 'Abcd123!']));
    }

    private function reset(string $biz, string $password)
    {
        Auth::logout();

        return Livewire::test('auth.reset')
            ->set('biz_number', $biz)
            ->set('password', $password)
            ->set('password_confirmation', $password)
            ->call('resetPassword');
    }

    private function approvedUser(?string $ci): User
    {
        $user = new User([
            'password' => 'Abcd123!', 'phone' => '01012345678', 'phone_verified_at' => now(),
            'ci_hash' => $ci ? Pass::ciHash($ci) : null,
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
