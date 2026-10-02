<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PostStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_주인이_상태를_바꾸면_저장되고_이력이_남는다(): void
    {
        $user = $this->approvedUser();
        $post = $this->postOf($user);

        Livewire::actingAs($user)
            ->test('posts.detail', ['post' => $post])
            ->set('status', PostStatus::Done->value);

        $this->assertSame(PostStatus::Done, $post->fresh()->status);

        $log = DB::table('post_status_logs')->where('post_idx', $post->idx)->first();
        $this->assertSame('Selling', $log->old_status);
        $this->assertSame('Done', $log->new_status);
        $this->assertSame($user->idx, $log->actor_idx);
    }

    public function test_남의_글은_상태를_못_바꾼다(): void
    {
        $owner = $this->approvedUser();
        $post = $this->postOf($owner);
        $stranger = $this->approvedUser('01099998888', '789-45-67891');

        Livewire::actingAs($stranger)
            ->test('posts.detail', ['post' => $post])
            ->set('status', PostStatus::Done->value)
            ->assertForbidden();

        $this->assertSame(PostStatus::Selling, $post->fresh()->status);
    }

    public function test_고를_수_없는_상태는_막는다(): void
    {
        $user = $this->approvedUser();
        $post = $this->postOf($user);

        Livewire::actingAs($user)
            ->test('posts.detail', ['post' => $post])
            ->set('status', PostStatus::Draft->value)
            ->assertStatus(422);

        $this->assertSame(PostStatus::Selling, $post->fresh()->status);
    }

    public function test_같은_값을_다시_골라도_이력이_쌓이지_않는다(): void
    {
        $user = $this->approvedUser();
        $post = $this->postOf($user);

        Livewire::actingAs($user)
            ->test('posts.detail', ['post' => $post])
            ->set('status', PostStatus::Selling->value);

        $this->assertSame(0, DB::table('post_status_logs')->where('post_idx', $post->idx)->count());
    }

    private function postOf(User $user): Post
    {
        return Post::create([
            'user_idx' => $user->idx,
            'post_type' => PostType::Sell,
            'category_id' => 15,
            'title' => '상태 변경 확인용 글',
            'description' => '설명',
            'status' => PostStatus::Selling,
            'is_hidden' => false,
            'region_code' => '11590102', 'region_name' => '서울 동작구 상도동',
            'photos' => [['key' => 'posts/x.jpg', 'alt' => null]],
        ]);
    }

    private function approvedUser(string $phone = '01012345678', string $number = '123-45-67891'): User
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
