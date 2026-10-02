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
use Livewire\Livewire;
use Tests\TestCase;

class MarketIndexTest extends TestCase
{
    use RefreshDatabase;

    private ?User $seller = null;

    public function test_검색은_단어마다_제목이나_설명에서_찾는다(): void
    {
        $this->makePost('PP 박스', ['description' => '규격 400x300, 300개']);
        $this->makePost('PE 봉투', ['description' => '400x300 규격 아님']);
        $this->makePost('100% 면장갑');

        Livewire::test('posts.index')
            ->set('keyword', '박스 400x300')             // 제목의 '박스' + 설명의 규격
            ->assertSee('PP 박스')
            ->assertDontSee('PE 봉투')
            ->set('keyword', '100%')                     // % 는 글자 그대로 찾는다
            ->assertSee('100% 면장갑')
            ->assertDontSee('PP 박스');
    }

    public function test_구해요는_예산을_보여준다(): void
    {
        $this->makePost('상자 구합니다', ['post_type' => PostType::Want, 'condition' => null, 'budget_amount' => 50000]);

        Livewire::test('posts.index')
            ->set('type', PostType::Want->value)
            ->assertSee('예산 50,000원')
            ->assertDontSee('가격 협의');
    }

    public function test_사이드바는_고른_대분류만_펼친다(): void
    {
        $child_button = "wire:click=\"\$set('category', 15)\"";     // 복합기·프린터(10) 아래 흑백(15)

        Livewire::test('posts.index')
            ->assertDontSeeHtml($child_button)
            ->set('category', 10)
            ->assertSeeHtml($child_button)
            ->assertDontSeeHtml("wire:click=\"\$set('category', 20)\"")   // 소모품(11) 아래는 접힌 채
            ->set('category', 15)
            ->assertSeeHtml($child_button);                                   // 중분류를 골라도 펼친 채
    }

    public function test_대분류를_고르면_하위_분류_매물까지_나온다(): void
    {
        // 복합기·프린터(10) 아래 흑백(15)·컬러(16). 토너(20)는 소모품(11) 아래라 안 나와야 한다
        $this->makePost('흑백 복합기 매물', ['category_id' => 15]);
        $this->makePost('컬러 복합기 매물', ['category_id' => 16]);
        $this->makePost('토너 매물', ['category_id' => 20]);

        Livewire::test('posts.index')
            ->set('category', 10)
            ->assertSee(['흑백 복합기 매물', '컬러 복합기 매물'])
            ->assertDontSee('토너 매물')
            ->assertSee('2건');
    }

    public function test_지역은_올라온_글의_지역만_고를_수_있고_시군구로_거른다(): void
    {
        $this->makePost('동작구 매물', ['region_code' => '11590102', 'region_name' => '서울 동작구 상도동']);
        $this->makePost('강남구 매물', ['region_code' => '11680101', 'region_name' => '서울 강남구 역삼동']);
        $this->makePost('분당구 매물', ['region_code' => '41135101', 'region_name' => '경기 성남시 분당구 정자동']);

        Livewire::test('posts.index')
            ->assertSee('성남시 분당구')                // 선택지는 올라온 글에서 나온다
            ->assertSee('서울 동작구')                  // 카드에는 시군구까지
            ->set('sido', '11')
            ->assertSee(['동작구 매물', '강남구 매물'])
            ->assertDontSee('분당구 매물')
            ->set('sigungu', '11590')
            ->assertSee('동작구 매물')
            ->assertDontSee('강남구 매물')
            ->set('sido', '41')                         // 시도를 바꾸면 시군구는 풀린다
            ->assertSet('sigungu', '')
            ->assertSee('분당구 매물');
    }

    public function test_조작된_이름의_글_하나가_선택지_이름을_바꾸지_못한다(): void
    {
        $this->makePost('정상 1', ['region_code' => '11590102', 'region_name' => '서울 동작구 상도동']);
        $this->makePost('정상 2', ['region_code' => '11590103', 'region_name' => '서울 동작구 흑석동']);
        $this->makePost('조작', ['region_code' => '11590104', 'region_name' => '가짜 가나다구 동']);

        $regions = Livewire::test('posts.index')->viewData('regions');

        $this->assertSame('서울', $regions['11']['name']);                 // 시도는 코드로
        $this->assertSame('동작구', $regions['11']['sigungus']['11590']);  // 시군구는 많이 쓰인 이름
    }

    public function test_지금_없는_지역이_주소에_남아_있으면_무시한다(): void
    {
        $this->makePost('서울 매물');

        Livewire::withQueryParams(['sido' => '41', 'sigungu' => '41135'])
            ->test('posts.index')
            ->assertSet('sido', '')
            ->assertSet('sigungu', '')
            ->assertSee('서울 매물');
    }

    public function test_지역_선택지는_캐시하되_새_글이_올라오면_바로_반영한다(): void
    {
        $this->makePost('서울 매물', ['region_code' => '11590102', 'region_name' => '서울 동작구 상도동']);
        $this->assertArrayNotHasKey('41', Livewire::test('posts.index')->viewData('regions'));

        $this->makePost('경기 매물', ['region_code' => '41135101', 'region_name' => '경기 성남시 분당구 정자동']);   // 저장하면 캐시가 비워진다

        $this->assertArrayHasKey('41', Livewire::test('posts.index')->viewData('regions'));
    }

    public function test_상세에_판매_업체와_그_업체의_다른_글이_보인다(): void
    {
        $this_post = $this->makePost('지금 보는 매물');
        $this->makePost('같은 업체의 다른 매물');
        $this->makePost('숨긴 매물', ['is_hidden' => true]);

        Livewire::test('posts.detail', ['post' => $this_post])
            ->assertSee(['판매 업체', '대성포장산업', '서울 동작구', '이 업체의 다른 글', '같은 업체의 다른 매물'])
            ->assertDontSee('숨긴 매물')
            ->assertSee('올린 글 2건');
    }

    public function test_메뉴_구해요는_구해요_목록으로_가고_지금_화면이_강조된다(): void
    {
        $want_list = route('market.index', ['type' => PostType::Want->value]);

        $this->get($want_list)
            ->assertSee('href="'.$want_list.'" class="active"', false)   // PC 메뉴 '구해요' 강조
            ->assertSee('구해요 · 곳간');
        $this->get(route('market.index'))
            ->assertDontSee('href="'.$want_list.'" class="active"', false);
        $this->actingAs($this->seller())->get(route('account.index'))
            ->assertSee('aria-current="page"', false)
            ->assertSee('내 정보');
    }

    public function test_더_보기는_다음_20건만_조회해서_덧붙인다(): void
    {
        foreach (range(1, 25) as $i) {
            $post = $this->makePost(sprintf('매물 %02d번', $i));
            $post->forceFill(['created_at' => now()->subMinutes(100 - $i)])->save();   // 25번이 가장 최근
        }

        $c = Livewire::test('posts.index')
            ->assertSee(['매물 25번', '매물 06번', '더 보기'])
            ->assertDontSee('매물 05번');                      // 처음엔 20건만
        $first = $c->instance()->chunk;
        $this->assertCount(20, $first['posts']);
        $this->assertTrue($first['more']);

        $c->call('more')->assertSet('page', 2);
        $second = $c->instance()->chunk;
        $this->assertSame(['매물 05번', '매물 04번', '매물 03번', '매물 02번', '매물 01번'], $second['posts']->pluck('title')->all());   // 새 5건만
        $this->assertFalse($second['more']);                   // 끝

        $c->set('keyword', '매물')->assertSet('page', 1);     // 조건을 바꾸면 처음 묶음부터
    }

    public function test_휴대폰_필터는_고른_조건을_요약하고_필터_초기화는_검색어를_남긴다(): void
    {
        $this->makePost('동작구 매물', ['region_code' => '11590102', 'region_name' => '서울 동작구 상도동']);

        Livewire::test('posts.index')
            ->set('keyword', '매물')
            ->set('category', 10)
            ->set('sido', '11')
            ->set('sigungu', '11590')
            ->assertSee('필터 2 · 복합기·프린터 · 서울 동작구')
            ->call('resetFilters')
            ->assertSet('sido', '')
            ->assertSet('keyword', '매물');
    }

    public function test_구해요는_사진_자리가_없다(): void
    {
        $this->makePost('상자 구합니다', ['post_type' => PostType::Want, 'condition' => null]);
        $this->makePost('파는 상자');

        Livewire::test('posts.index')
            ->assertSee('사진 미등록')
            ->set('type', PostType::Want->value)
            ->assertDontSee('사진 미등록');
    }

    public function test_오늘_올라온_글이_없으면_오늘_등록_박스를_숨긴다(): void
    {
        $old = $this->makePost('지난주 매물');
        $old->forceFill(['created_at' => now()->subDays(7)])->save();

        Livewire::test('posts.index')->assertDontSee('오늘 등록');

        $this->makePost('오늘 매물');
        cache()->forget('market.counts');

        Livewire::test('posts.index')->assertSee('오늘 등록');
    }

    public function test_상단_띠에_종류별_건수가_나오고_하루_안의_글에만_새_글_배지가_붙는다(): void
    {
        $this->makePost('오늘 매물');
        $this->makePost('상자 구합니다', ['post_type' => PostType::Want, 'condition' => null]);
        $old = $this->makePost('지난주 매물');
        $old->forceFill(['created_at' => now()->subDays(7)])->save();

        $html = Livewire::test('posts.index')
            ->assertSeeHtml('<b>2</b>건 판매중')
            ->assertSeeHtml('<b>1</b>건 구해요')
            ->assertViewHas('counts', fn ($counts) => $counts['today'] == [PostType::Sell->value => 1, PostType::Want->value => 1])   // 지난주 글은 빠진다
            ->html();

        // 배지와 제목 사이에 Livewire 주석(<!--[if ENDBLOCK]-->)이 끼므로 조금 떨어져 있어도 본다
        $this->assertMatchesRegularExpression('/새 글<\/span>.{0,120}?오늘 매물/s', $html);
        $this->assertDoesNotMatchRegularExpression('/새 글<\/span>.{0,120}?지난주 매물/s', $html);
    }

    public function test_휴대폰에서도_약관에_닿도록_로그인과_내_정보_본문에_약관_링크가_있다(): void
    {
        $privacy = 'href="'.route('privacy').'"';

        // 푸터에 하나, 본문에 하나. 휴대폰 장터는 자동 불러오기라 푸터에 닿기 어렵다
        $this->assertSame(2, substr_count($this->get(route('login'))->getContent(), $privacy));
        $this->assertSame(2, substr_count($this->actingAs($this->seller())->get(route('account.index'))->getContent(), $privacy));
    }

    public function test_비회원에게만_가입_안내가_보인다(): void
    {
        $this->get(route('market.index'))->assertSee('사업자 회원 가입');
        $this->actingAs($this->seller())->get(route('market.index'))->assertDontSee('사업자 회원 가입');
    }

    private function makePost(string $title, array $overrides = []): Post
    {
        $this->seller ??= $this->seller();

        return Post::create($overrides + [
            'user_idx' => $this->seller->idx, 'post_type' => PostType::Sell, 'category_id' => 15, 'title' => $title,
            'description' => '설명', 'condition' => 1, 'status' => PostStatus::Selling, 'is_hidden' => false,
            'price_amount' => 10000, 'region_code' => '11590102', 'region_name' => '서울 동작구 상도동', 'photos' => [],
        ]);
    }

    private function seller(): User
    {
        $user = new User([
            'password' => 'Abcd123!', 'phone' => '01012345678', 'phone_verified_at' => now(),
            'name' => '홍길동', 'company_name' => '대성포장산업', 'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => UserRole::Member, 'status' => UserStatus::Active, 'review_status' => ApplicationStatus::Approved,
            'submitted_at' => now(), 'approved_at' => now(),
            'existence_status' => ExistenceStatus::Active, 'existence_checked_at' => now(),
        ]);
        $user->biz_no = '123-45-67891';
        $user->save();

        return $user;
    }
}
