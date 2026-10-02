<?php

/*
 * ┌─ 매물 관리 상세 ────────────────────────────────────────────────
 * │ 오는 길    GET /admin/posts/{post}  ·  auth + admin
 * │
 * │ 하는 일    숨김 / 숨김 해제. 되돌리기의 기본 자리다
 * │            신고를 거치지 않고 운영자가 직접 숨길 수도 있다
 * │
 * │ 안 하는 일 판매 상태(status)는 건드리지 않는다. 그건 판매자 몫이다
 * └────────────────────────────────────────────────────────────────
 */

use App\Enums\ReportTarget;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\Report;
use App\Support\Moderation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '매물 관리'])]
class extends Component
{
    #[Locked]
    public int $postIdx;

    public string $reason = '';

    public ?string $notice = null;

    // 번호로만 받는다. Post 로 받으면 Post::resolveRouteBinding 이 숨긴 글을 404 로 막아서
    // 관리자가 숨김 해제 화면에 들어올 수 없다. 실제 조회는 아래 with() 의 findOrFail 이 한다.
    public function mount(int $post): void
    {
        $this->postIdx = $post;
    }

    public function with(): array
    {
        $post = Post::with('user')->findOrFail($this->postIdx);

        return [
            'post' => $post,
            'reports' => Report::where('target_type', ReportTarget::Post)
                ->where('target_idx', $post->idx)
                ->with('reporter:idx,company_name')
                ->orderByDesc('idx')
                ->get(),
            'logs' => AuditLog::where('target_type', 'post')
                ->where('target_idx', $post->idx)
                ->with('actor:idx,company_name')
                ->orderByDesc('occurred_at')
                ->limit(10)
                ->get(),
        ];
    }

    public function hide(): void
    {
        $post = Post::findOrFail($this->postIdx);

        if ($post->is_hidden) {
            return;
        }

        Moderation::hidePost($post, trim($this->reason) ?: null);
        $this->notice = '매물을 숨겼습니다. 목록과 상세에서 보이지 않습니다.';
        $this->reason = '';
    }

    public function unhide(): void
    {
        $post = Post::findOrFail($this->postIdx);

        if (! $post->is_hidden) {
            return;
        }

        if ($post->user->status === UserStatus::Withdrawn) {
            $this->addError('reason', '탈퇴한 회원의 매물은 숨김을 해제할 수 없습니다.');

            return;
        }

        // 복구는 왜 풀었는지가 남아야 한다. 숨길 때보다 이쪽이 더 중요하다.
        if (trim($this->reason) === '') {
            $this->addError('reason', '복구 사유를 적어 주세요.');

            return;
        }

        Moderation::unhidePost($post, trim($this->reason));
        $this->notice = '숨김을 해제했습니다. 판매 상태는 그대로입니다.';
        $this->reason = '';
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <a class="back" href="{{ route('admin.posts') }}">{!! App\Support\Icon::render('back') !!}매물 관리</a>
            <h1>#{{ $post->idx }} {{ $post->title }}</h1>
            <p class="sub">{{ $post->user->company_name ?? '탈퇴 회원' }}</p>
        </div>
        <div class="adm-head-actions">
            <a class="adm-btn" href="{{ route('posts.detail', $post) }}">매물 상세</a>
            @if ($post->user)
                <a class="adm-btn" href="{{ route('admin.user', $post->user) }}">회원 관리</a>
            @endif
        </div>
    </div>

    @if ($notice)
        <div class="adm-notice">{!! App\Support\Icon::render('check') !!}<span>{{ $notice }}</span></div>
    @endif

    <div class="adm-card">
        <div class="adm-card-head"><h2>현재 상태</h2></div>
        <div class="adm-card-body tight">
            <dl class="adm-facts">
                <dt>노출</dt>
                <dd><span class="adm-badge {{ $post->is_hidden ? 'off' : 'done' }}">{{ $post->is_hidden ? '운영자 숨김' : '노출' }}</span></dd>
                <dt>판매 상태</dt>
                <dd><span class="adm-badge gray">{{ $post->status->label($post->post_type) }}</span></dd>
                <dt>가격</dt>
                <dd>{{ $post->price_amount ? number_format($post->price_amount).'원' : '협의' }}</dd>
                <dt>지역</dt>
                <dd>{{ $post->region_name ?? '지역 미정' }}</dd>
                <dt>등록일</dt>
                <dd>{{ $post->created_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</dd>
                <dt>설명</dt>
                <dd style="white-space:pre-wrap">{{ $post->description }}</dd>
            </dl>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card-head"><h2>{{ $post->is_hidden ? '숨김 해제' : '글 숨김' }}</h2></div>
        <div class="adm-card-body">
            <div class="field" style="margin-bottom:12px">
                <label for="reason">{{ $post->is_hidden ? '복구 사유' : '숨김 사유' }}</label>
                <textarea id="reason" wire:model="reason" style="min-height:76px"
                          placeholder="{{ $post->is_hidden ? '왜 다시 노출하는지 적어 주세요.' : '왜 숨기는지 적어 주세요.' }}"></textarea>
                @error('reason') <div class="error">{{ $message }}</div> @enderror
            </div>

            @if ($post->is_hidden)
                <button type="button" class="adm-btn primary" wire:click="unhide">숨김 해제</button>
            @else
                <button type="button" class="adm-btn danger" wire:click="hide">글 숨김</button>
            @endif
        </div>
    </div>

    @if ($reports->isNotEmpty())
        <div class="adm-card">
            <div class="adm-card-head">
                <h2>이 매물에 들어온 신고 {{ $reports->count() }}건</h2>
                <div class="spacer"></div>
                <a class="adm-btn tiny" href="{{ route('admin.report', ['type' => ReportTarget::Post->value, 'idx' => $post->idx]) }}">신고 처리 화면</a>
            </div>
            <div class="adm-card-body tight">
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>상태</th><th>번호</th><th>신고자</th><th>사유</th><th>신고일</th></tr></thead>
                        <tbody>
                        @foreach ($reports as $report)
                            <tr>
                                <td><span class="adm-badge {{ $report->status === App\Enums\ReportStatus::Received ? 'wait' : 'gray' }}">{{ $report->status->label() }}</span></td>
                                <td>#{{ $report->idx }}</td>
                                <td>{{ $report->reporter->company_name ?? '탈퇴 회원' }}</td>
                                <td class="muted">{{ \Illuminate\Support\Str::limit($report->reason, 50) }}</td>
                                <td class="muted">{{ $report->created_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if ($logs->isNotEmpty())
        <div class="adm-card">
            <div class="adm-card-head"><h2>조치 기록</h2></div>
            <div class="adm-card-body tight">
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>행위</th><th>처리자</th><th>사유</th><th>시각</th></tr></thead>
                        <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td><span class="adm-badge gray">{{ $log->label() }}</span></td>
                                <td>{{ $log->actorLabel() }}</td>
                                <td class="muted">{{ $log->reason }}</td>
                                <td class="muted">{{ $log->occurred_at->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
