<?php

/*
 * ┌─ 문의함 ────────────────────────────────────────────────────────
 * │ 오는 길    GET /inquiries  ·  auth + approved
 * │            routes/web.php  Route::livewire('/inquiries', 'inquiries.index')
 * │
 * │ 내놀 것    $rooms        내가 낀 대화방. 마지막 대화가 위로 온다
 * │            $reply_count  내가 건 문의 중 답장이 온 사람 수
 * │
 * │ 다시 오는 길
 * │            탭 클릭 → $tab 만 바뀌고 with() 가 다시 돈다
 * │            wire:poll.30s → 30초마다 with() 가 다시 돈다
 * │
 * │ 나가는 길  방 클릭 → /inquiries/{room} 대화
 * │
 * │ 전체 흐름은 docs/가이드.md 12장
 * └────────────────────────────────────────────────────────────────
 */

use App\Models\Inquiry;
use Livewire\Component;

new class extends Component
{
    // 받은 문의 / 보낸 문의. 마크업이 $set 으로 값만 바꾸고 with() 가 다시 돈다.
    public string $tab = 'received';

    public function with(): array
    {
        $me = auth()->id();

        // 받은 문의냐 보낸 문의냐는 '누가 방을 먼저 열었나'로 가른다.
        // 내가 from 이면 내가 말을 건 방, to 면 상대가 건 방이다.
        $column = $this->tab === 'sent' ? 'from_user_idx' : 'to_user_idx';

        $rooms = Inquiry::query()
            ->where($column, $me)
            // 상대 업체명과 마지막 메시지를 목록에서 바로 읽는다. 미리 불러야 방마다 쿼리가 안 나간다.
            ->with(['from', 'to', 'lastMessage', 'lastPostMessage.post:idx,title'])
            // 내가 아직 안 읽은 메시지 수. 방마다 세지 않고 한 번에 붙인다.
            ->withCount(['messages as unread_count' => function ($q) use ($me) {
                $q->where('sender_idx', '!=', $me)->whereNull('read_at');
            }])
            ->orderByDesc('last_message_at')
            ->get();

        return [
            'rooms' => $rooms,
            'reply_count' => auth()->user()->unreadReplyCount(),
        ];
    }
}; ?>

{{--
 ┌─ 문의함 · HTML ─────────────────────────────────────────────────
 │ 시안 index.html 의 messages() 를 옮긴 것.
 │ 방은 사람 한 쌍에 하나다. 같은 사람에게 다른 매물을 문의해도 같은 방에 들어간다.
 │
 │ 매물 사진과 매물명은 뺐다 — 지금은 대화만 보여준다.
 │ DB 에는 messages.post_idx 가 그대로 쌓이므로 되살릴 때 마크업만 되돌리면 된다.
 └────────────────────────────────────────────────────────────────
--}}
<div class="narrow" wire:poll.30s>
    <div class="page-head">
        <div>
            <h1>문의함</h1>
            <p class="sub">문의한 업체와 나눈 대화를 확인하세요.</p>
        </div>
    </div>

    {{-- 받은 문의와 보낸 문의를 나눠 본다. 거르는 일은 서버가 하므로
         누르면 $tab 만 바꾸고 with() 가 다시 돈다. --}}
    <div class="chips" style="margin-bottom:18px">
        <button type="button" wire:click="$set('tab', 'received')"
                @class(['chip', 'active' => $tab === 'received'])>받은 문의</button>
        <button type="button" wire:click="$set('tab', 'sent')"
                @class(['chip', 'active' => $tab === 'sent'])>보낸 문의</button>
    </div>

    {{-- 내가 건 문의에 답장이 온 경우. 헤더 말풍선 배지는 '나에게 온 문의' 만 세므로
         이쪽은 보낸 문의 탭에서 따로 알린다. --}}
    @if ($tab === 'sent' && $reply_count)
        <div class="inline-callout" style="margin-bottom:18px">
            {!! App\Support\Icon::render('chat') !!}
            <span class="grow">
                <strong>답장이 왔습니다</strong>
                <p class="sub">문의한 업체 {{ $reply_count }}곳이 답장했습니다.</p>
            </span>
        </div>
    @endif

    @forelse ($rooms as $room)
        <a class="thread-item" href="{{ route('inquiries.show', $room) }}"
           style="border:1px solid var(--line);border-radius:12px;background:white;margin-bottom:12px">
            <div class="company">
                <div class="grow" style="flex:1;min-width:0">   {{-- 남은 너비를 다 차지해야 시각이 늘 오른쪽 끝에 붙는다 --}}
                    <div class="chips">
                        <h3 style="margin:0">{{ $room->partner->company_name }}</h3>
                        {{-- 가장 최근에 얘기한 매물 --}}
                        @if ($room->lastPostMessage?->post)
                            <span class="dim" style="font-size:13px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">· {{ $room->lastPostMessage->post->title }}</span>
                        @endif
                        @if ($room->unread_count)
                            <span class="badge red">{{ $room->unread_count > 9 ? '9+' : $room->unread_count }}</span>
                        @endif
                    </div>

                    {{-- 줄바꿈이 들어간 메시지도 목록에서는 한 줄로만 보여준다. --}}
                    <p style="margin-top:7px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        {{ $room->lastMessage?->body ?? '아직 주고받은 메시지가 없습니다.' }}
                    </p>
                </div>

                <span class="sub" style="flex:none;align-self:flex-start">
                    {{ $room->last_message_at?->diffForHumans(short: true) }}
                </span>
            </div>
        </a>
    @empty
        @if ($tab === 'received')
            <div class="empty">
                {!! App\Support\Icon::render('chat') !!}
                <h2>받은 문의가 없습니다</h2>
                <p>등록한 재고에 문의가 오면 여기에 쌓입니다.<br>
                    사진과 설명을 자세히 적을수록 문의가 늘어납니다.</p>
                <div class="actions">
                    <a class="btn primary" href="{{ route('posts.create') }}">재고 등록하기</a>
                </div>
            </div>
        @else
            <div class="empty">
                {!! App\Support\Icon::render('search') !!}
                <h2>보낸 문의가 없습니다</h2>
                <p>필요한 재고를 찾아 판매자에게 문의해 보세요.<br>
                    문의해도 수량이 예약되거나 차감되지는 않습니다.</p>
                <div class="actions">
                    <a class="btn primary" href="{{ route('market.index') }}">장터 둘러보기</a>
                </div>
            </div>
        @endif
    @endforelse

    <p class="legal">문의로 수량이 예약되거나 차감되지는 않습니다.</p>
</div>
