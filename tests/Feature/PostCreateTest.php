<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class PostCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_등록하기가_글을_하나_만든다(): void
    {
        Livewire::actingAs($this->approvedUser())
            ->test('posts.create')
            ->set('photos', [UploadedFile::fake()->image('a.jpg')])
            ->set('title', '골판지 상자 3호 500장')
            ->set('description', '창고 정리 물량입니다.')
            ->set('condition', 1)
            ->set('region_code', '11590102')->set('region_name', '서울 동작구 상도동')
            ->set('price_amount', 50000)
            ->set('show_phone', '0')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Post::count());
        $this->assertSame(PostStatus::Selling, Post::first()->status);
        $this->assertFalse(Post::first()->show_phone);

        Livewire::actingAs($this->approvedUser('01055556666', '456-45-67891'))
            ->test('posts.detail', ['post' => Post::first()])
            ->assertSee('연락처 비공개')
            ->assertDontSee('010-1234-5678');
    }

    public function test_공개한_연락처는_버튼을_누른_뒤에만_보인다(): void
    {
        $user = $this->approvedUser();
        $post = Post::create([
            'user_idx' => $user->idx,
            'post_type' => PostType::Sell,
            'category_id' => 15,
            'title' => '연락처 공개 테스트 매물',
            'description' => '연락처는 버튼을 누른 뒤에 표시합니다.',
            'condition' => 1,
            'status' => PostStatus::Selling,
            'is_hidden' => false,
            'region_code' => '11590102', 'region_name' => '서울 동작구 상도동',
            'show_phone' => true,
            'photos' => [],
        ]);

        Livewire::actingAs($this->approvedUser('01055556666', '456-45-67891'))
            ->test('posts.detail', ['post' => $post])
            ->assertSee('연락처 보기')
            ->assertDontSee('010-1234-5678')
            ->call('revealContact')
            ->assertSee('010-1234-5678')
            ->call('hideContact')                 // 다시 누르면 숨긴다
            ->assertDontSee('010-1234-5678')
            ->assertSee('연락처 보기');

        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('contact_access_logs')->count());   // 숨길 때는 열람 기록이 안 남는다
    }

    public function test_자동저장은_행을_하나만_만들고_등록시에도_늘지_않는다(): void
    {
        $c = Livewire::actingAs($this->approvedUser())
            ->test('posts.create')
            ->set('photos', [UploadedFile::fake()->image('a.jpg')])
            ->set('title', '골판지 상자 3호 500장')
            ->set('description', '창고 정리 물량입니다.')
            ->set('region_code', '11590102')->set('region_name', '서울 동작구 상도동');

        $this->assertSame(1, Post::count(), '자동저장이 행을 여러 개 만들었다');
        $this->assertSame(PostStatus::Draft, Post::first()->status);
        $this->assertSame([], Post::first()->photos, '임시저장에 사진이 들어갔다');

        $c->set('condition', 1)->set('price_amount', 50000)->call('save')->assertHasNoErrors();

        $this->assertSame(1, Post::count(), '등록하기가 글을 하나 더 만들었다');
        $this->assertSame(PostStatus::Selling, Post::first()->fresh()->status);
        $this->assertCount(1, Post::first()->photos);
    }

    public function test_주소를_고르면_읍면동까지만_남고_자동저장된다(): void
    {
        Livewire::actingAs($this->approvedUser())
            ->test('posts.create')
            ->set('title', '지역 확인용 매물')
            ->call('pickRegion', ['bcode' => '4182025021', 'sido' => '경기', 'sigungu' => '가평군', 'bname' => '대곡리', 'bname1' => '가평읍'])
            ->assertSet('region_code', '41820250')
            ->assertSet('region_name', '경기 가평군 가평읍');   // 리는 버린다

        $this->assertSame('경기 가평군 가평읍', Post::first()->region_name);
    }

    public function test_새_글에는_사업장_주소가_기본으로_채워진다(): void
    {
        $user = $this->approvedUser();
        $user->update(['region_code' => '11590102', 'region_name' => '서울 동작구 상도동']);

        Livewire::actingAs($user)->test('posts.create')
            ->assertSet('region_code', '11590102')
            ->assertSet('region_name', '서울 동작구 상도동');

        // 예전 가입자(시군구까지만)는 비워 두고 안내한다
        $user->update(['region_code' => '11590', 'region_name' => '서울 동작구']);
        Livewire::actingAs($user->fresh())->test('posts.create')
            ->assertSet('region_code', '')
            ->assertSee('사업장 주소를 다시 고르면');
    }

    public function test_검증실패_모달의_확인이_에러를_지운다(): void
    {
        Livewire::actingAs($this->approvedUser())
            ->test('posts.create')
            ->call('save')
            ->assertHasErrors('photos')
            ->call('dismissErrors')
            ->assertHasNoErrors();
    }

    public function test_모달을_무시하고_써도_임시저장은_한_개다(): void
    {
        $user = $this->approvedUser();

        // 1) 먼저 임시저장 글을 하나 만든다
        Livewire::actingAs($user)->test('posts.create')->set('title', '먼저 쓰던 글입니다');
        $this->assertSame(1, Post::where('status', PostStatus::Draft)->count());

        // 2) 새 화면을 열고(= post_idx 는 null) 모달을 고르지 않은 채 그냥 쓴다
        Livewire::actingAs($user)->test('posts.create')->set('title', '모달 무시하고 쓴 글');

        $this->assertSame(1, Post::where('status', PostStatus::Draft)->count(), '임시저장이 두 개가 됐다');
        $this->assertSame('모달 무시하고 쓴 글', Post::where('status', PostStatus::Draft)->first()->title);
    }

    public function test_고를_수_없는_카테고리는_거부한다(): void
    {
        // 중분류가 있는 대분류(10 복합기·프린터)는 고를 수 없고 중분류만 고른다
        $this->assertSame('복합기·프린터 › 레이저 복합기(흑백)', Category::selectable()[15]);
        $this->assertArrayNotHasKey(10, Category::selectable());
        $this->assertArrayNotHasKey(1, Category::selectable());    // 옛 포장재는 2026-10-02 에 꺼졌다

        $user = $this->approvedUser();

        foreach ([1 => '꺼진 옛 포장재', 10 => '하위가 있는 복합기·프린터', 77 => '없는 번호'] as $category_id => $why) {
            Livewire::actingAs($user)
                ->test('posts.create')
                ->set('photos', [UploadedFile::fake()->image('a.jpg')])
                ->set('title', '골판지 상자 3호 500장')
                ->set('description', '창고 정리 물량입니다.')
                ->set('condition', 1)
                ->set('region_code', '11590102')->set('region_name', '서울 동작구 상도동')
                ->set('price_amount', 50000)
                ->set('category_id', $category_id)
                ->call('save')
                ->assertHasErrors('category_id');
        }

        $this->assertSame(0, Post::where('status', PostStatus::Selling)->count());
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
