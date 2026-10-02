<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\BizNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 관리자 계정을 만든다. 로그인은 일반 회원처럼 10자리 번호로 한다.
 *
 * 관리자 번호는 '관리자 전용 번호'만 받는다 — 국세청 검증 규칙을 통과하지 않는 10자리(예: 000-00-00001).
 * 실제 사업자번호는 가입 화면이 검증 규칙으로 받으므로 절대 겹치지 않고, 누구도 그 번호로 가입할 수 없다.
 * 실제 사업자번호를 관리자로 쓰면 그 사업자는 회원으로 가입할 수 없게 된다(2026-09-29).
 *
 * 비밀번호는 --password 로 주지 않으면 화면에 보이지 않게 두 번 묻는다. 대화형이 아니면 임의로 만들어 한 번 보여 준다.
 *   docker exec -it gotgan-laravel.test-1 php artisan gotgan:admin 000-00-00001 010-1234-5678
 */
class CreateAdmin extends Command
{
    protected $signature = 'gotgan:admin {number : 관리자 전용 번호(검증 규칙을 통과하지 않는 10자리)} {phone : 관리자 휴대폰(정보 수정 본인인증용)}'
        .' {--name=운영자} {--company=곳간 운영} {--password=}';

    protected $description = '관리자 계정을 만든다. 번호는 실제 사업자번호가 아닌 관리자 전용 번호만 받는다.';

    public function handle(): int
    {
        $number = BizNumber::normalize($this->argument('number'));

        if (strlen($number) !== 10) {
            $this->error('번호는 숫자 10자리여야 합니다.');

            return self::FAILURE;
        }

        if (BizNumber::isValid($number)) {
            $this->error('실제 사업자번호 형식입니다. 관리자에게는 검증 규칙을 통과하지 않는 관리자 전용 번호(예: 000-00-00001)를 쓰세요.');

            return self::FAILURE;
        }

        if (User::where('biz_no', $number)->exists()) {
            $this->error('이미 쓰고 있는 번호입니다.');

            return self::FAILURE;
        }

        $password = $this->password();
        if ($password === null) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($number, $password) {
            $user = new User([
                'password' => $password,
                'phone' => preg_replace('/\D/', '', $this->argument('phone')),
                'phone_verified_at' => now(),
                'name' => $this->option('name'),
                'company_name' => $this->option('company'),
                'region_code' => null,
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
                'review_status' => ApplicationStatus::Approved,
                'approved_at' => now(),
                'existence_status' => ExistenceStatus::Active,
                'existence_checked_at' => now(),
            ]);
            $user->biz_no = $number;
            $user->save();
        });

        $this->info('관리자 계정을 만들었습니다. 관리자 화면은 /admin 입니다.');
        $this->line('  아이디   '.BizNumber::format($number));

        return self::SUCCESS;
    }

    /**
     * 개발 환경(APP_ENV=local)은 1234 로 고정한다(2026-09-29 요청). 운영에서는 추측되는 비밀번호라 쓰지 않는다.
     * 그 밖에는 --password → 대화형이면 두 번 묻기 → 아니면 임의로 만들어 한 번 보여 주기. 회원과 같은 비밀번호 규칙을 쓴다.
     */
    private function password(): ?string
    {
        if (app()->environment('local')) {
            $this->line('  비밀번호 1234   (개발 환경 고정값)');

            return '1234';
        }

        if ($this->option('password')) {
            $password = (string) $this->option('password');
        } elseif ($this->input->isInteractive()) {
            $password = (string) $this->secret('비밀번호 (8자 이상, 대문자·소문자·숫자·특수기호 각 1개 이상)');
            if ($password !== (string) $this->secret('비밀번호 확인')) {
                $this->error('두 비밀번호가 다릅니다.');

                return null;
            }
        } else {
            $password = str()->password(12);
            $this->line('  비밀번호 '.$password.'   (한 번만 보여 줍니다. 로그인 후 정보 수정에서 바꾸세요)');
        }

        $check = Validator::make(
            ['password' => $password, 'password_confirmation' => $password],
            ['password' => User::passwordRules('password_confirmation')],
            User::passwordMessages(),
        );
        if ($check->fails()) {
            $this->error($check->errors()->first('password'));

            return null;
        }

        return $password;
    }
}
