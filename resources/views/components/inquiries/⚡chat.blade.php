<?php

/*
 * ┌─ 문의 대화 ─────────────────────────────────────────────────────
 * │ 오는 길    GET /inquiries/{room}  ·  auth + approved
 * │            routes/web.php  Route::livewire('/inquiries/{room}', 'inquiries.chat')
 * │            {room} 이 아래 public Inquiry $room 과 짝이 된다
 * │
 * │ 내놀 것    $messages  오래된 것이 위로 오는 메시지 목록
 * │
 * │ 다시 오는 길
 * │            전송 → send()  ·  wire:poll.30s → 30초마다 with() 가 다시 돈다
 * │
 * │ 전체 흐름은 docs/가이드.md 12장
 * └────────────────────────────────────────────────────────────────
 */

use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Enums\UserStatus;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\Report;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public Inquiry $room;

    /**
     * 들어올 때 안 읽었던 첫 메시지. 그 위에 '여기서부터 새 메시지' 줄을 긋고 그 자리로 스크롤한다(2026-09-29).
     * 읽음 처리는 들어오는 순간 전부 한다(카카오톡 방식). 이 값은 화면을 떠날 때까지 그대로 둔다.
     */
    #[Locked]
    public ?int $first_unread = null;

    public string $draft = '';

    public bool $report_open = false;
    public string $report_reason = '';
    public bool $reported = false;

    public function mount(): void
    {
        $this->assertInRoom();

        // 읽음 처리 전에, 어디부터 새 메시지였는지 기억해 둔다
        $this->first_unread = $this->room->messages()
            ->where('sender_idx', '!=', auth()->id())
            ->whereNull('read_at')
            ->min('idx');

        // 들어오자마자 한 번 털어준다. 이게 없으면 폴링이 처음 도는 30초 동안
        // 대화를 보고 있는데도 헤더 배지가 남는다.
        $this->markAsRead();
    }

    public function with(): array
    {
        return [
            'messages' => $this->room->messages()
                ->with(['sender:idx', 'post'])
                ->orderBy('idx')
                ->get(),
        ];
    }

    /**
     * 상대가 보낸 안 읽은 메시지에 읽은 시각을 찍는다.
     *
     * 화면의 wire:poll.30s 가 이 메서드를 직접 부른다. with() 안에 숨겨두면
     * 입력·정렬 등 아무 재렌더링에서나 같이 돌아서 의도가 드러나지 않는다.
     * 조건이 WHERE 에 있으므로 털 것이 없으면 0건 갱신으로 끝난다 —
     * 미리 exists() 로 확인하면 SELECT 만 한 번 더 늘어난다.
     */
    public function markAsRead(): void
    {
        $this->assertInRoom();

        $this->room->messages()
            ->where('sender_idx', '!=', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function send(): void
    {
        // 화면에 폼이 없어도 요청은 보낼 수 있다. 권한을 여기서 다시 본다.
        abort_unless(auth()->check() && auth()->user()->isApproved(), 403);
        $this->assertInRoom();

        $this->validate([
            'draft' => ['required', 'min:1', 'max:1000'],
        ]);

        if ($this->room->partner?->status === UserStatus::Withdrawn) {
            $this->addError('draft', '탈퇴한 회원에게는 메시지를 보낼 수 없습니다.');

            return;
        }

        if ($error = Message::tooFast(auth()->id())) {
            $this->addError('draft', $error);

            return;
        }

        // 메시지만 남고 방의 마지막 시각이 안 바뀌면 목록 정렬이 어긋난다. 둘을 묶는다.
        DB::transaction(function () {
            Message::create([
                'inquiry_idx' => $this->room->idx,
                'sender_idx' => auth()->id(),
                'body' => $this->draft,
            ]);

            $this->room->update(['last_message_at' => now()]);
        });

        $this->draft = '';
    }

    public function openReport(): void
    {
        $this->assertInRoom();

        // 검토 중이거나 최근 기각된 신고가 있으면 모달을 열기 전에 알린다
        if ($blocked = Report::blockReason(auth()->id(), ReportTarget::User, $this->room->partner->idx)) {
            $this->addError('report', $blocked);

            return;
        }

        $this->report_open = true;
    }

    public function closeReport(): void
    {
        $this->report_open = false;
        $this->report_reason = '';
        $this->resetErrorBag();
    }

    /**
     * 대화 상대를 신고한다. 대상은 회원(ReportTarget::User)이다.
     * 관리자는 신고 상세에서 신고자와 이 회원의 대화를 함께 보고 판단한다.
     */
    public function report(): void
    {
        // 모달이 화면에 없어도 요청은 보낼 수 있다. 권한을 여기서 다시 본다.
        abort_unless(auth()->check() && auth()->user()->isApproved(), 403);
        $this->assertInRoom();

        $partner = $this->room->partner;

        // 탈퇴한 회원은 로그인이 막혀 있어 조치할 것이 없다
        abort_if($partner->status === UserStatus::Withdrawn, 403);

        $this->validate(
            ['report_reason' => ['required', 'min:5', 'max:500']],
            [],
            ['report_reason' => '신고 사유'],
        );

        // 판단은 Report 한 곳에서만 한다(매물 신고와 같은 규칙)
        if ($blocked = Report::blockReason(auth()->id(), ReportTarget::User, $partner->idx)) {
            $this->addError('report_reason', $blocked);

            return;
        }

        Report::create([
            'reporter_idx' => auth()->id(),
            'target_type' => ReportTarget::User,
            'target_idx' => $partner->idx,
            'reason' => $this->report_reason,
            'status' => ReportStatus::Received,
        ]);

        $this->reported = true;
        $this->closeReport();
    }

    /** 남의 대화를 주소로 열거나 건드릴 수 없어야 한다. 메서드마다 다시 본다. */
    private function assertInRoom(): void
    {
        abort_unless(
            in_array(auth()->id(), [$this->room->from_user_idx, $this->room->to_user_idx], true),
            403
        );
    }
}; ?>

{{--
 ┌─ 문의 대화 · HTML ──────────────────────────────────────────────
 │ 시안 index.html 의 chat() 을 옮긴 것.
 │ 방은 사람 한 쌍에 하나다.
 │
 │ 매물 카드와 조건 스냅샷 표시는 뺐다 — 지금은 대화만 보여준다.
 │ DB 에는 messages.post_idx·terms_snapshot 이 그대로 쌓이므로
 │ 되살릴 때 이 마크업만 되돌리면 된다.
 └────────────────────────────────────────────────────────────────
--}}
<div class="narrow" wire:poll.30s="markAsRead">
    <a class="back" href="{{ route('inquiries.index') }}">{!! App\Support\Icon::render('back') !!}문의함</a>

    <div class="page-head">
        <div>
            <h1>{{ $room->partner->company_name }}</h1>
            <p class="sub">{{ $room->partner->regionName() }}</p>
        </div>
        @unless ($room->partner->status === UserStatus::Withdrawn)
            @if ($reported)
                <span class="sub">신고를 접수했습니다</span>
            @else
                <button type="button" class="text-button" wire:click="openReport">이 회원 신고</button>
            @endif
        @endunless
    </div>
    @error('report') <p class="error">{{ $message }}</p> @enderror

    {{-- 매물 신고 모달과 같은 모양. ESC·바깥클릭으로 닫혀도 서버 상태가 남지 않게 cancel 을 알린다. --}}
    @if ($report_open)
        <dialog x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeReport()">
            <div class="dialog-header">
                <h2>{{ $room->partner->company_name }} 신고하기</h2>
            </div>

            <div class="dialog-body">
                <div class="field">
                    <label for="report_reason">신고 사유<span class="required">*</span></label>
                    <textarea id="report_reason" wire:model="report_reason"
                              placeholder="어떤 일이 있었는지 적어 주세요. 예: 거래와 무관한 연락을 반복함"></textarea>
                    @error('report_reason')
                        <div class="error">{{ $message }}</div>
                    @enderror
                    <small>운영자가 이 대화 내용을 함께 확인한 뒤 조치합니다. 결과는 따로 안내하지 않습니다.</small>
                </div>
            </div>

            <div class="dialog-footer">
                <button type="button" class="btn" wire:click="closeReport">취소</button>
                <button type="button" class="btn primary" wire:click="report">신고하기</button>
            </div>
        </dialog>
    @endif

    <section class="messages-box">
        {{-- 대화 칸만 스크롤한다. 열면 맨 아래(최신)로 가고, 맨 아래를 보고 있을 때만 새 메시지를 따라 내려간다.
             위로 올려 예전 대화를 읽는 중이면 끌어내리지 않는다. --}}
        {{-- 안 읽은 메시지가 있었으면 '여기서부터 새 메시지' 줄로, 없으면 맨 아래로 연다. --}}
        <div class="bubbles" x-data="{ stick: true }"
             x-init="const d = $el.querySelector('.unread-divider');
                     $el.scrollTop = d ? $el.scrollTop + d.getBoundingClientRect().top - $el.getBoundingClientRect().top - 8 : $el.scrollHeight;
                     stick = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 60;
                     new MutationObserver(() => { if (stick) $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, subtree: true })"
             x-on:scroll="stick = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 60">
            @forelse ($messages as $message)
                @if ($message->idx === $first_unread)
                    <div class="unread-divider" role="separator"><span>여기서부터 새 메시지</span></div>
                @endif
                {{-- 매물 상세에서 보낸 문의에는 어떤 매물이었는지 붙인다. 가격은 보낸 순간 값(terms_snapshot) --}}
                @if ($message->post_idx)
                    @php
                        $p = $message->post;
                        $viewable = $p && ! $p->is_hidden && $p->status !== App\Enums\PostStatus::Draft
                            && (! $p->isExpired() || $p->user_idx === auth()->id());
                        $price = $message->terms_snapshot['price'] ?? null;
                    @endphp
                    <div @class(['msg-post', 'self' => $message->sender_idx === auth()->id()])>
                        <span class="dim">문의한 매물</span>
                        @if ($viewable)
                            <a href="{{ route('posts.detail', $p) }}">{{ $p->title }}</a>
                        @else
                            <span>{{ $p?->title ?? '삭제된 매물' }} <span class="dim">(지금은 볼 수 없음)</span></span>
                        @endif
                        @if ($price)
                            <span class="dim">· 문의 당시 {{ number_format($price) }}원</span>
                        @endif
                    </div>
                @endif
                <div @class(['bubble', 'self' => $message->sender_idx === auth()->id()])>
                    {{-- 줄바꿈만 살리고 HTML 은 막는다. --}}
                    <span style="white-space:pre-wrap">{{ $message->body }}</span>
                    <small>
                        {{ $message->created_at->timezone('Asia/Seoul')->format('m-d H:i') }}
                        @if ($message->sender_idx === auth()->id() && $message->read_at) · 읽음 @endif
                    </small>
                </div>
            @empty
                <p class="sub" style="text-align:center;padding:24px 0">아직 주고받은 메시지가 없습니다.</p>
            @endforelse
        </div>

        <form class="composer" wire:submit="send">
            <label class="sr" for="draft">메시지 내용</label>
            <input id="draft" wire:model="draft" placeholder="메시지 입력" maxlength="1000" autocomplete="off">
            <button class="btn primary" type="submit" wire:loading.attr="disabled">전송</button>
        </form>
    </section>

    @error('draft') <p class="error">{{ $message }}</p> @enderror

    <p class="legal">문의로 수량이 예약되거나 차감되지는 않습니다.</p>
</div>
