<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceCheckResult;
use App\Enums\ExistenceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ExistenceCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_국세청_결과를_회원별로_기록하고_조회_실패는_폐업으로_보지_않는다(): void
    {
        config(['gotgan.bizno.driver' => 'nts', 'gotgan.bizno.api_key' => 'SECRET+KEY/==']);

        $active = $this->approvedUser('01011110000', '1234567891');
        $closed = $this->approvedUser('01022220000', '2234567891');
        $broken = $this->approvedUser('01033330000', '3234567891');
        $pending = $this->approvedUser('01044440000', '4234567891');
        $pending->update(['review_status' => ApplicationStatus::Reviewing]);

        // 사업자번호마다 국세청 응답을 다르게 준다. 세 번째는 서버 오류.
        Http::fake(function (Request $request) {
            return match ($request->data()['b_no'][0]) {
                '1234567891' => Http::response(['data' => [['b_stt_cd' => '01']]]),
                '2234567891' => Http::response(['data' => [['b_stt_cd' => '03']]]),
                default => Http::response('error serviceKey=SECRET%2BKEY%2F%3D%3D', 500),
            };
        });

        $this->artisan('app:business-check')->assertSuccessful();

        $result_of = fn (User $user) => ExistenceCheck::where('user_idx', $user->idx)->value('result');

        $this->assertSame(ExistenceCheckResult::Active, $result_of($active));
        $this->assertSame(ExistenceCheckResult::Closed, $result_of($closed));
        $this->assertSame(ExistenceCheckResult::Failed, $result_of($broken));
        $this->assertNull($result_of($pending));   // 승인 전 회원은 조회하지 않는다

        // 오류 문구를 남기되 키는 가린다
        $raw = ExistenceCheck::where('user_idx', $broken->idx)->value('raw_status_code');
        $this->assertStringNotContainsString('SECRET', $raw);

        // 키가 주소에 실려 간다
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'serviceKey=SECRET%2BKEY%2F%3D%3D'));
    }

    private function approvedUser(string $phone, string $number): User
    {
        $user = new User([
            'password' => 'Abcd123!',
            'phone' => $phone,
            'phone_verified_at' => now(),
            'name' => '홍길동',
            'company_name' => '대성포장산업',
            'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
            'review_status' => ApplicationStatus::Approved,
            'submitted_at' => now(),
            'approved_at' => now(),
            'existence_status' => ExistenceStatus::Active,
            'existence_checked_at' => now(),
        ]);
        $user->biz_no = $number;
        $user->save();

        return $user->fresh();
    }
}
