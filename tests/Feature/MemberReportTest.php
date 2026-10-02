<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MemberReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_대화_상대를_신고하면_회원_신고가_접수된다(): void
    {
        [$me, $partner, $room] = $this->roomOfTwo();

        Livewire::actingAs($me)
            ->test('inquiries.chat', ['room' => $room])
            ->assertSee('이 회원 신고')
            ->call('openReport')
            ->assertSet('report_open', true)
            ->set('report_reason', '거래와 무관한 연락을 반복합니다.')
            ->call('report')
            ->assertHasNoErrors()
            ->assertSet('reported', true)
            ->assertSee('신고를 접수했습니다');

        $report = Report::first();
        $this->assertSame(ReportTarget::User, $report->target_type);
        $this->assertSame($partner->idx, $report->target_idx);
        $this->assertSame($me->idx, $report->reporter_idx);
        $this->assertSame(ReportStatus::Received, $report->status);
    }

    public function test_검토_중인_신고가_있으면_다시_신고할_수_없다(): void
    {
        [$me, $partner, $room] = $this->roomOfTwo();
        Report::create([
            'reporter_idx' => $me->idx, 'target_type' => ReportTarget::User,
            'target_idx' => $partner->idx, 'reason' => '먼저 낸 신고', 'status' => ReportStatus::Received,
        ]);

        Livewire::actingAs($me)
            ->test('inquiries.chat', ['room' => $room])
            ->call('openReport')
            ->assertHasErrors('report')
            ->assertSet('report_open', false)
            // 모달을 거치지 않고 바로 불러도 막힌다
            ->set('report_reason', '한 번 더 신고합니다.')
            ->call('report')
            ->assertHasErrors('report_reason');

        $this->assertSame(1, Report::count());
    }

    public function test_대화에_끼지_않은_사람은_신고_화면에_들어올_수_없다(): void
    {
        [, , $room] = $this->roomOfTwo();
        $stranger = $this->approvedUser('01077776666', '456-45-67891');

        Livewire::actingAs($stranger)
            ->test('inquiries.chat', ['room' => $room])
            ->assertForbidden();
    }

    public function test_관리자는_신고자와의_대화를_보고_열람_기록이_남는다(): void
    {
        [$me, $partner, $room] = $this->roomOfTwo();
        Message::create(['inquiry_idx' => $room->idx, 'sender_idx' => $partner->idx, 'body' => '계속 연락드립니다']);
        Report::create([
            'reporter_idx' => $me->idx, 'target_type' => ReportTarget::User,
            'target_idx' => $partner->idx, 'reason' => '반복 연락', 'status' => ReportStatus::Received,
        ]);

        // 대화가 없는 신고자도 하나 둔다 — 관리자 화면이 빈 방을 만들면 안 된다
        $other = $this->approvedUser('01055554444', '789-45-67891');
        Report::create([
            'reporter_idx' => $other->idx, 'target_type' => ReportTarget::User,
            'target_idx' => $partner->idx, 'reason' => '사칭 의심', 'status' => ReportStatus::Received,
        ]);

        $admin = $this->approvedUser('01011112222', '211-88-12346');
        $admin->update(['role' => UserRole::Admin]);

        Livewire::actingAs($admin->fresh())
            ->test('admin.report', ['type' => ReportTarget::User->value, 'idx' => $partner->idx])
            ->assertSee('신고자와의 대화')
            ->assertSee('계속 연락드립니다')
            ->assertSee('두 회원이 주고받은 메시지가 없습니다.');

        $this->assertSame(1, AuditLog::where('action', 'report.conversation.view')->where('target_idx', $partner->idx)->count());
        $this->assertSame(1, Inquiry::count());   // 방을 새로 만들지 않았다
    }

    /** @return array{User, User, Inquiry} */
    private function roomOfTwo(): array
    {
        $me = $this->approvedUser();
        $partner = $this->approvedUser('01099998888', '321-45-67891');

        return [$me, $partner, Inquiry::between($me, $partner)];
    }

    private function approvedUser(string $phone = '01012345678', string $number = '123-45-67891'): User
    {
        $user = new User([
            'password' => 'Abcd123!',
            'phone' => $phone,
            'phone_verified_at' => now(),
            'name' => '홍길동',
            'company_name' => '대성포장산업'.$phone,
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
