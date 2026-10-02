<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\PostStatus;
use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Enums\ReviewDecision;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApplicationReview;
use App\Models\AuditLog;
use App\Models\ContactConsent;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_보유기간이_지난_것만_지우고_미리보기는_아무것도_지우지_않는다(): void
    {
        Storage::fake('local');
        $admin = $this->user('1088612463', '01099990000');
        $admin->update(['role' => UserRole::Admin]);

        // ── 반려 신청: 오래된 것 / 최근 것
        $old_rejected = $this->user('1234567891', '01010000001', ApplicationStatus::Rejected);
        ApplicationReview::create(['user_idx' => $old_rejected->idx, 'reviewer_idx' => $admin->idx,
            'decision' => ReviewDecision::Rejected, 'reason' => '반려', 'reviewed_at' => now()->subDays(100)]);
        $this->age($old_rejected, 100);
        Storage::disk('local')->put($old_rejected->licenseKey(), 'x');

        $new_rejected = $this->user('2345678904', '01010000002', ApplicationStatus::Rejected);
        ApplicationReview::create(['user_idx' => $new_rejected->idx, 'reviewer_idx' => $admin->idx,
            'decision' => ReviewDecision::Rejected, 'reason' => '반려', 'reviewed_at' => now()->subDays(10)]);

        // ── 등록증: 승인 100일 전 / 10일 전
        $old_approved = $this->user('3456789013', '01010000003');
        $old_approved->forceFill(['approved_at' => now()->subDays(100)])->save();
        Storage::disk('local')->put($old_approved->licenseKey(), 'x');
        $new_approved = $this->user('4567890121', '01010000004');
        Storage::disk('local')->put($new_approved->licenseKey(), 'x');

        // ── 탈퇴 100일 전인 회원의 매물(사진·동의·열람 기록·대화 연결 포함)
        $seller = $this->user('1111111119', '01010000005');
        Storage::disk('local')->put('posts/old.jpg', 'x');
        $old_post = $this->makePost($seller, [['key' => 'posts/old.jpg', 'alt' => null]]);
        ContactConsent::syncFor($old_post->fresh());
        DB::table('contact_access_logs')->insert(['viewer_user_idx' => $new_approved->idx, 'post_idx' => $old_post->idx,
            'accessed_at' => now()->subDays(1), 'created_at' => now(), 'updated_at' => now()]);
        $room = Inquiry::between($new_approved, $seller);
        $message = Message::create(['inquiry_idx' => $room->idx, 'sender_idx' => $new_approved->idx, 'post_idx' => $old_post->idx, 'body' => '문의']);
        $room->update(['last_message_at' => now()]);
        $seller->withdraw();
        $seller->forceFill(['withdrawn_at' => now()->subDays(100)])->save();

        // ── 열람 기록: 100일 전 / 10일 전 (살아 있는 매물)
        $live_post = $this->makePost($new_approved, []);
        foreach ([100, 10] as $days) {
            DB::table('contact_access_logs')->insert(['viewer_user_idx' => $old_approved->idx, 'post_idx' => $live_post->idx,
                'accessed_at' => now()->subDays($days), 'created_at' => now(), 'updated_at' => now()]);
        }

        // ── 신고: 처리 100일 전 / 미처리 100일 전
        $done = Report::create(['reporter_idx' => $old_approved->idx, 'target_type' => ReportTarget::Post,
            'target_idx' => $live_post->idx, 'reason' => '처리됨', 'status' => ReportStatus::Resolved]);
        $open = Report::create(['reporter_idx' => $old_approved->idx, 'target_type' => ReportTarget::User,
            'target_idx' => $new_approved->idx, 'reason' => '미처리', 'status' => ReportStatus::Received]);
        Report::whereIn('idx', [$done->idx, $open->idx])->update(['updated_at' => now()->subDays(100)]);

        // ── 운영자 조치 기록: 100일 전(남는다) / 400일 전(지운다)
        foreach ([100, 400] as $days) {
            AuditLog::create(['action' => 'post.hidden', 'target_type' => 'post', 'target_idx' => $live_post->idx, 'occurred_at' => now()->subDays($days)]);
        }

        // ── 대화방: 마지막 메시지 400일 전(지운다) / 100일 전(남는다)
        $old_room = Inquiry::between($old_approved, $new_approved);
        $old_room->update(['last_message_at' => now()->subDays(400)]);
        $mid_room = Inquiry::between($old_approved, $admin);
        $mid_room->update(['last_message_at' => now()->subDays(100)]);

        // ── 미리보기: 아무것도 지우지 않는다
        $before = [User::where('status', UserStatus::Withdrawn)->count(), Post::count(), Report::count(), AuditLog::count(), Inquiry::count()];
        $this->artisan('app:purge', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, [User::where('status', UserStatus::Withdrawn)->count(), Post::count(), Report::count(), AuditLog::count(), Inquiry::count()]);
        $this->assertTrue(Storage::disk('local')->exists($old_approved->licenseKey()));

        // ── 실제 실행
        $this->artisan('app:purge')->assertSuccessful();

        // 반려: 오래된 것만 개인정보 삭제
        $this->assertSame(UserStatus::Withdrawn, $old_rejected->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'user.purged')->where('target_idx', $old_rejected->idx)->count());
        $this->assertFalse(Storage::disk('local')->exists($old_rejected->licenseKey()));
        $this->assertSame(UserStatus::Active, $new_rejected->fresh()->status);

        // 등록증: 승인 90일 지난 것만
        $this->assertFalse(Storage::disk('local')->exists($old_approved->licenseKey()));
        $this->assertTrue(Storage::disk('local')->exists($new_approved->licenseKey()));

        // 탈퇴 회원 매물: 사진·동의·열람 기록과 함께 삭제, 대화는 남고 매물 연결만 비워진다
        $this->assertNull(Post::find($old_post->idx));
        $this->assertFalse(Storage::disk('local')->exists('posts/old.jpg'));
        $this->assertSame(0, ContactConsent::where('post_idx', $old_post->idx)->count());
        $this->assertNotNull($message->fresh());
        $this->assertNull($message->fresh()->post_idx);

        // 열람 기록: 90일 지난 것만
        $this->assertSame(1, DB::table('contact_access_logs')->where('post_idx', $live_post->idx)->count());

        // 신고: 처리된 것만 지우고 미처리는 남긴다
        $this->assertNull(Report::find($done->idx));
        $this->assertNotNull(Report::find($open->idx));

        // 조치 기록·대화: 1년 기준
        $this->assertSame(1, AuditLog::where('action', 'post.hidden')->count());
        $this->assertNull(Inquiry::find($old_room->idx));
        $this->assertNotNull(Inquiry::find($mid_room->idx));
    }

    /** created_at·updated_at 을 과거로 돌린다(모델 저장은 updated_at 을 지금으로 바꾸므로 쿼리로). */
    private function age(User $user, int $days): void
    {
        User::where('idx', $user->idx)->update(['updated_at' => now()->subDays($days)]);
    }

    private function makePost(User $user, array $photos): Post
    {
        return Post::create([
            'user_idx' => $user->idx, 'post_type' => 1, 'category_id' => 15,
            'title' => '보관 기간 확인용', 'description' => '설명', 'condition' => 1,
            'status' => PostStatus::Selling, 'is_hidden' => false, 'region_code' => '11590102', 'region_name' => '서울 동작구 상도동',
            'show_phone' => true, 'photos' => $photos,
        ]);
    }

    private function user(string $biz, string $phone, ApplicationStatus $review = ApplicationStatus::Approved): User
    {
        $user = new User([
            'password' => 'Abcd123!', 'phone' => $phone, 'phone_verified_at' => now(),
            'name' => '홍길동', 'company_name' => '회원'.$phone, 'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => UserRole::Member, 'status' => UserStatus::Active, 'review_status' => $review,
            'submitted_at' => now(), 'approved_at' => $review === ApplicationStatus::Approved ? now() : null,
            'existence_status' => ExistenceStatus::Active, 'existence_checked_at' => now(),
        ]);
        $user->biz_no = $biz;
        $user->save();

        return $user->fresh();
    }
}
