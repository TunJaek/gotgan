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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PostEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_수정화면은_기존_사진을_들고_온다(): void
    {
        $user = $this->approvedUser();
        $post = $this->postOf($user, ['a.jpg', 'b.jpg']);

        Livewire::actingAs($user)
            ->test('posts.create', ['post' => $post])
            ->assertSet('is_edit', true)
            ->assertSet('title', '수정 확인용 글')
            ->assertCount('photos', 2);
    }

    public function test_사진을_빼고_저장하면_파일도_지운다(): void
    {
        $user = $this->approvedUser();
        $post = $this->postOf($user, ['a.jpg', 'b.jpg']);
        [$first, $second] = array_column($post->photos, 'key');

        Livewire::actingAs($user)
            ->test('posts.create', ['post' => $post])
            ->call('removePhoto', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$first], array_column($post->fresh()->photos, 'key'));
        $this->assertTrue(Storage::disk('local')->exists($first));
        $this->assertFalse(Storage::disk('local')->exists($second));
    }

    public function test_수정해도_상태는_그대로다(): void
    {
        $user = $this->approvedUser();
        $post = $this->postOf($user, ['a.jpg']);
        $post->update(['status' => PostStatus::Stopped]);

        Livewire::actingAs($user)
            ->test('posts.create', ['post' => $post])
            ->set('title', '제목만 바꿉니다')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(PostStatus::Stopped, $post->fresh()->status);
        $this->assertSame('제목만 바꿉니다', $post->fresh()->title);
    }

    public function test_남의_글은_수정화면이_안_열린다(): void
    {
        $owner = $this->approvedUser();
        $post = $this->postOf($owner, ['a.jpg']);
        $stranger = $this->approvedUser('01099998888', '789-45-67891');

        Livewire::actingAs($stranger)
            ->test('posts.create', ['post' => $post])
            ->assertForbidden();
    }

    public function test_새_사진은_기존_사진_뒤에_붙는다(): void
    {
        $user = $this->approvedUser();
        $post = $this->postOf($user, ['a.jpg']);

        Livewire::actingAs($user)
            ->test('posts.create', ['post' => $post])
            ->set('upload', [UploadedFile::fake()->image('c.jpg')])
            ->assertCount('photos', 2)
            ->assertSet('upload', []);
    }

    private function postOf(User $user, array $names): Post
    {
        Storage::fake('local');

        $photos = [];
        foreach ($names as $name) {
            $photos[] = [
                'key' => UploadedFile::fake()->image($name)->store('posts', 'local'),
                'alt' => null,
            ];
        }

        return Post::create([
            'user_idx' => $user->idx,
            'post_type' => PostType::Sell,
            'category_id' => 15,
            'title' => '수정 확인용 글',
            'description' => '설명입니다.',
            'condition' => 1,
            'status' => PostStatus::Selling,
            'is_hidden' => false,
            'region_code' => '11590102', 'region_name' => '서울 동작구 상도동',
            'price_amount' => 50000,
            'vat_mode' => 1,
            'photos' => $photos,
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
