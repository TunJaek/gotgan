<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class InquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_문의하면_방이_열리고_첫_메시지가_남는다(): void
    {
        $seller = $this->approvedUser();
        $buyer = $this->approvedUser('01099998888', '789-45-67891');
        $post = $this->postOf($seller);

        Livewire::actingAs($buyer)
            ->test('posts.detail', ['post' => $post])
            ->call('openInquiry')
            ->assertSet('inquiry_open', true)
            ->set('inquiry_message', '남은 수량이 얼마나 되나요?')
            ->call('sendInquiry')
            ->assertHasNoErrors();

        $room = Inquiry::first();
        $this->assertSame($buyer->idx, $room->from_user_idx);   // 말을 건 사람이 from
        $this->assertSame($seller->idx, $room->to_user_idx);

        // 매물 표시는 화면에서 뺐지만 저장은 계속한다. 되살릴 때 쓰인다.
        $message = Message::first();
        $this->assertSame($post->idx, $message->post_idx);
        $this->assertNotNull($message->terms_snapshot);
    }

    public function test_같은_사람에게_두_번_문의해도_방은_하나다(): void
    {
        $seller = $this->approvedUser();
        $buyer = $this->approvedUser('01099998888', '789-45-67891');

        foreach ([$this->postOf($seller), $this->postOf($seller)] as $post) {
            Livewire::actingAs($buyer)
                ->test('posts.detail', ['post' => $post])
                ->set('inquiry_message', '수량 확인 부탁드립니다.')
                ->call('sendInquiry')
                ->assertHasNoErrors();
        }

        $this->assertSame(1, Inquiry::count());
        $this->assertSame(2, Message::count());
    }

    public function test_반대_방향으로_문의해도_기존_방에_들어간다(): void
    {
        $a = $this->approvedUser();
        $b = $this->approvedUser('01099998888', '789-45-67891');

        // A 가 B 의 글에 먼저 문의한다.
        Livewire::actingAs($a)
            ->test('posts.detail', ['post' => $this->postOf($b)])
            ->set('inquiry_message', '재고 있나요? 확인 부탁드립니다.')
            ->call('sendInquiry');

        // 이번엔 B 가 A 의 글에 문의한다. 방향이 반대지만 같은 두 사람이다.
        Livewire::actingAs($b)
            ->test('posts.detail', ['post' => $this->postOf($a)])
            ->set('inquiry_message', '이쪽도 수량 확인 부탁드립니다.')
            ->call('sendInquiry');

        $this->assertSame(1, Inquiry::count());
    }

    public function test_자기_글에는_문의할_수_없다(): void
    {
        $owner = $this->approvedUser();

        Livewire::actingAs($owner)
            ->test('posts.detail', ['post' => $this->postOf($owner)])
            ->set('inquiry_message', '자기 글에 문의해 봅니다.')
            ->call('sendInquiry')
            ->assertForbidden();

        $this->assertSame(0, Inquiry::count());
    }

    public function test_남의_대화는_주소로_못_연다(): void
    {
        $a = $this->approvedUser();
        $b = $this->approvedUser('01099998888', '789-45-67891');
        $stranger = $this->approvedUser('01077776666', '456-45-67891');

        $room = Inquiry::between($a, $b);

        Livewire::actingAs($stranger)
            ->test('inquiries.chat', ['room' => $room])
            ->assertForbidden();
    }

    public function test_대화를_열면_상대_메시지가_읽음_처리된다(): void
    {
        $a = $this->approvedUser();
        $b = $this->approvedUser('01099998888', '789-45-67891');

        $room = Inquiry::between($a, $b);
        $mine = $this->messageIn($room, $b);      // 내가 보낸 것
        $theirs = $this->messageIn($room, $a);    // 상대가 보낸 것

        Livewire::actingAs($b)->test('inquiries.chat', ['room' => $room]);

        $this->assertNotNull($theirs->fresh()->read_at);
        $this->assertNull($mine->fresh()->read_at);   // 내 메시지는 건드리지 않는다
    }

    public function test_안_읽었던_첫_메시지_위에_새_메시지_구분선이_생긴다(): void
    {
        $a = $this->approvedUser();
        $b = $this->approvedUser('01099998888', '789-45-67891');
        $room = Inquiry::between($a, $b);

        $old = $this->messageIn($room, $a);
        $old->update(['body' => '예전에 읽은 메시지', 'read_at' => now()]);
        $first_new = $this->messageIn($room, $a);
        $first_new->update(['body' => '처음 안 읽은 메시지']);
        $this->messageIn($room, $a)->update(['body' => '두 번째 안 읽은 메시지']);

        $chat = Livewire::actingAs($b)->test('inquiries.chat', ['room' => $room])
            ->assertSet('first_unread', $first_new->idx);
        $this->assertMatchesRegularExpression('/예전에 읽은 메시지.*여기서부터 새 메시지.*처음 안 읽은 메시지/s', $chat->html());
        $this->assertSame(0, $room->messages()->whereNull('read_at')->where('sender_idx', $a->idx)->count());   // 읽음 처리는 전부

        // 다시 들어오면 새 메시지가 없으니 구분선도 없다
        Livewire::actingAs($b)->test('inquiries.chat', ['room' => $room])
            ->assertSet('first_unread', null)
            ->assertDontSee('여기서부터 새 메시지');
    }

    public function test_대화와_문의함에_어떤_매물_문의인지_보인다(): void
    {
        $seller = $this->approvedUser();
        $buyer = $this->approvedUser('01099998888', '789-45-67891');
        $post = $this->postOf($seller);
        $room = Inquiry::between($buyer, $seller);
        Message::create([
            'inquiry_idx' => $room->idx, 'sender_idx' => $buyer->idx, 'post_idx' => $post->idx,
            'terms_snapshot' => Message::snapshotOf($post), 'body' => '재고 있나요?',
        ]);
        $room->update(['last_message_at' => now()]);

        Livewire::actingAs($seller)->test('inquiries.chat', ['room' => $room])
            ->assertSee('문의한 매물')
            ->assertSee($post->title)
            ->assertSee(route('posts.detail', $post));
        Livewire::actingAs($seller)->test('inquiries.index')->assertSee('· '.$post->title);

        $post->update(['is_hidden' => true]);   // 숨겨진 매물은 링크 없이 이름만
        Livewire::actingAs($buyer)->test('inquiries.chat', ['room' => $room])
            ->assertSee('지금은 볼 수 없음')
            ->assertDontSee(route('posts.detail', $post));
    }

    public function test_열어둔_채_받은_메시지도_폴링이_읽음_처리한다(): void
    {
        $a = $this->approvedUser();
        $b = $this->approvedUser('01099998888', '789-45-67891');

        $room = Inquiry::between($a, $b);
        $chat = Livewire::actingAs($b)->test('inquiries.chat', ['room' => $room]);

        // 화면을 열어둔 뒤에 상대가 보낸 메시지. mount() 는 다시 돌지 않는다.
        $late = $this->messageIn($room, $a);
        $this->assertNull($late->fresh()->read_at);

        // 30초마다 폴링이 부르는 그 액션.
        $chat->call('markAsRead');

        $this->assertNotNull($late->fresh()->read_at);
    }

    public function test_안_읽은_문의는_사람_수로_센다(): void
    {
        $seller = $this->approvedUser();
        $buyer = $this->approvedUser('01099998888', '789-45-67891');

        $room = Inquiry::between($buyer, $seller);   // 구매자가 먼저 연 방
        $this->messageIn($room, $buyer);
        $this->messageIn($room, $buyer);             // 한 사람이 두 번 보내도 1이다

        $this->assertSame(1, $seller->fresh()->unreadInquiryCount());
        $this->assertSame(0, $seller->fresh()->unreadReplyCount());

        // 판매자가 답장하면 구매자 쪽에 '답장 왔음'으로 잡힌다.
        $this->messageIn($room, $seller);
        $this->assertSame(1, $buyer->fresh()->unreadReplyCount());
        $this->assertSame(0, $buyer->fresh()->unreadInquiryCount());
    }

    public function test_대화창에서_메시지를_보낼_수_있다(): void
    {
        $a = $this->approvedUser();
        $b = $this->approvedUser('01099998888', '789-45-67891');
        $room = Inquiry::between($a, $b);

        Livewire::actingAs($b)
            ->test('inquiries.chat', ['room' => $room])
            ->set('draft', '내일 방문 가능합니다.')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('draft', '');

        $message = Message::first();
        $this->assertSame($b->idx, $message->sender_idx);
        $this->assertNull($message->post_idx);            // 대화 중 메시지에는 매물이 안 붙는다
        $this->assertNotNull($room->fresh()->last_message_at);
    }

    public function test_1분에_20건을_넘으면_보내지_않는다(): void
    {
        $a = $this->approvedUser();
        $b = $this->approvedUser('01099998888', '789-45-67891');
        $room = Inquiry::between($a, $b);
        $chat = Livewire::actingAs($b)->test('inquiries.chat', ['room' => $room]);

        for ($i = 1; $i <= 20; $i++) {
            $chat->set('draft', "메시지 {$i}")->call('send')->assertHasNoErrors();
        }

        $chat->set('draft', '21번째')->call('send')->assertHasErrors('draft');

        $this->assertSame(20, Message::count());
    }

    public function test_하루에_연락처를_본_매물이_30건이면_새_매물은_막고_봤던_매물은_다시_볼_수_있다(): void
    {
        $seller = $this->approvedUser();
        $buyer = $this->approvedUser('01099998888', '789-45-67891');
        $seen = [];
        foreach (range(1, 30) as $i) {
            $seen[] = $post = $this->postOf($seller);
            DB::table('contact_access_logs')->insert(['viewer_user_idx' => $buyer->idx, 'post_idx' => $post->idx, 'accessed_at' => now()->subHour()]);
        }
        $new_post = $this->postOf($seller);
        $new_post->update(['show_phone' => true]);
        $seen[0]->update(['show_phone' => true]);

        Livewire::actingAs($buyer)->test('posts.detail', ['post' => $new_post])
            ->call('revealContact')
            ->assertHasErrors('action')
            ->assertSet('contact_visible', false);
        Livewire::actingAs($buyer)->test('posts.detail', ['post' => $seen[0]])
            ->call('revealContact')
            ->assertHasNoErrors()
            ->assertSet('contact_visible', true);
    }

    public function test_하루에_문의한_매물이_20건이면_새_매물에는_문의할_수_없다(): void
    {
        $seller = $this->approvedUser();
        $buyer = $this->approvedUser('01099998888', '789-45-67891');
        $room = Inquiry::between($buyer, $seller);
        foreach (range(1, 20) as $i) {
            DB::table('messages')->insert([
                'inquiry_idx' => $room->idx, 'sender_idx' => $buyer->idx, 'post_idx' => $this->postOf($seller)->idx,
                'body' => '문의', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
            ]);
        }

        Livewire::actingAs($buyer)->test('posts.detail', ['post' => $this->postOf($seller)])
            ->set('inquiry_message', '21번째 매물 문의입니다.')
            ->call('sendInquiry')
            ->assertHasErrors('inquiry_message');
        $this->assertSame(20, Message::count());
    }

    public function test_상세의_문의_버튼_위에_업체와_사업자_확인일이_보인다(): void
    {
        $seller = $this->approvedUser();
        $buyer = $this->approvedUser('01099998888', '789-45-67891');

        Livewire::actingAs($buyer)->test('posts.detail', ['post' => $this->postOf($seller)])
            ->assertSee('사업자 서류 확인 '.$seller->approved_at->format('Y.n.j'))
            ->assertSee('연락처 비공개 매물입니다.');
    }

    public function test_문의함은_방을_연_사람_기준으로_갈린다(): void
    {
        $seller = $this->approvedUser();
        $buyer = $this->approvedUser('01099998888', '789-45-67891');

        $room = Inquiry::between($buyer, $seller);
        $this->messageIn($room, $buyer);

        // 판매자에게는 '받은 문의'
        Livewire::actingAs($seller)
            ->test('inquiries.index')
            ->assertViewHas('rooms', fn ($rooms) => $rooms->count() === 1)
            ->set('tab', 'sent')
            ->assertViewHas('rooms', fn ($rooms) => $rooms->isEmpty());

        // 구매자에게는 '보낸 문의'
        Livewire::actingAs($buyer)
            ->test('inquiries.index')
            ->assertViewHas('rooms', fn ($rooms) => $rooms->isEmpty())
            ->set('tab', 'sent')
            ->assertViewHas('rooms', fn ($rooms) => $rooms->count() === 1);
    }

    private function messageIn(Inquiry $room, User $sender): Message
    {
        $message = Message::create([
            'inquiry_idx' => $room->idx,
            'sender_idx' => $sender->idx,
            'body' => '문의 드립니다.',
        ]);

        $room->update(['last_message_at' => now()]);

        return $message;
    }

    private function postOf(User $user): Post
    {
        return Post::create([
            'user_idx' => $user->idx,
            'post_type' => PostType::Sell,
            'category_id' => 15,
            'title' => '문의 확인용 글',
            'description' => '설명입니다.',
            'status' => PostStatus::Selling,
            'is_hidden' => false,
            'region_code' => '11590102', 'region_name' => '서울 동작구 상도동',
            'photos' => [],
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
