<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\ReviewDecision;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SupplementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $applicant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = $this->makeUser('211-88-12346', '01011112222', UserRole::Admin);
        $this->applicant = $this->makeUser('123-45-67891', '01012345678');
        Storage::disk('local')->put($this->applicant->licenseKey(), '처음 낸 등록증');
    }

    public function test_보완요청이_아니면_보완_화면에_들어올_수_없다(): void
    {
        $this->actingAs($this->applicant)
            ->get(route('account.supplement'))
            ->assertRedirect(route('account.index'));
    }

    public function test_제출하면_정보가_바뀌고_심사중으로_돌아간다(): void
    {
        $this->requestSupplement('상호가 등록증과 다릅니다.');
        $before = $this->applicant->fresh();

        Livewire::actingAs($before)
            ->test('account.supplement')
            ->assertSet('company_name', '대성포장산업')
            ->assertSee('상호가 등록증과 다릅니다.')
            ->set('company_name', '대성포장산업(주)')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('account.index'));

        $after = $this->applicant->fresh();
        $this->assertSame('대성포장산업(주)', $after->company_name);
        $this->assertSame(ApplicationStatus::Reviewing, $after->review_status);
        $this->assertSame($before->lock_version + 1, $after->lock_version);
        $this->assertSame(1, AuditLog::where('action', 'application.resubmitted')->where('actor_idx', $after->idx)->count());

        // 파일을 안 올렸으면 처음 낸 등록증을 그대로 쓴다
        $this->assertSame('처음 낸 등록증', Storage::disk('local')->get($after->licenseKey()));
    }

    public function test_새_등록증을_올리면_기존_파일을_대신한다(): void
    {
        $this->requestSupplement('등록증이 흐립니다.');

        Livewire::actingAs($this->applicant->fresh())
            ->test('account.supplement')
            ->set('license', UploadedFile::fake()->createWithContent('new.pdf', '새 등록증'))
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame('새 등록증', Storage::disk('local')->get($this->applicant->licenseKey()));
    }

    public function test_재제출_전에_열어_둔_심사_화면으로는_처리할_수_없다(): void
    {
        $this->requestSupplement('상호가 등록증과 다릅니다.');

        // 관리자가 보완요청 상태의 신청을 열어 둔다
        $stale = Livewire::actingAs($this->admin)->test('admin.review', ['user' => $this->applicant->fresh()]);

        Livewire::actingAs($this->applicant->fresh())->test('account.supplement')->call('submit');

        $stale->set('decision', ReviewDecision::Rejected->value)
            ->set('reason', '재제출 내용을 보지 않고 반려')
            ->call('save')
            ->assertHasErrors('decision');

        $this->assertSame(ApplicationStatus::Reviewing, $this->applicant->fresh()->review_status);
    }

    private function requestSupplement(string $reason): void
    {
        Livewire::actingAs($this->admin)
            ->test('admin.review', ['user' => $this->applicant])
            ->set('decision', ReviewDecision::Supplement->value)
            ->set('reason', $reason)
            ->call('save')
            ->assertHasNoErrors();
    }

    private function makeUser(string $bizNumber, string $phone, UserRole $role = UserRole::Member): User
    {
        $user = new User([
            'password' => 'Abcd123!',
            'phone' => $phone,
            'phone_verified_at' => now(),
            'name' => $role === UserRole::Admin ? '운영자' : '홍길동',
            'company_name' => $role === UserRole::Admin ? '곳간 운영' : '대성포장산업',
            'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => $role,
            'status' => UserStatus::Active,
            'review_status' => $role === UserRole::Admin ? ApplicationStatus::Approved : ApplicationStatus::Reviewing,
            'submitted_at' => now(),
            'existence_status' => ExistenceStatus::Active,
            'existence_checked_at' => now(),
        ]);
        $user->biz_no = $bizNumber;
        $user->save();

        return $user->fresh();
    }
}
