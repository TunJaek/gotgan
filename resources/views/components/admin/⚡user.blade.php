<?php

/*
 * ┌─ 회원 관리 상세 ────────────────────────────────────────────────
 * │ 오는 길    GET /admin/users/{user}  ·  auth + admin
 * │
 * │ 하는 일    이용정지 / 정지 해제. 되돌리기의 기본 자리다
 * │            정지하면 그 회원의 매물도 함께 숨고, 해제하면 그때 숨긴 것만 되살아난다
 * │            회원 화면의 '운영자에게 문의' 안내를 여기서 처리한다(2026-09-29, 모두 사유 필수·조치 기록)
 * │              · 상호·대표자명 변경 — 사업자등록증이 바뀐 경우
 * │              · 휴대폰·본인인증 초기화 — 번호가 바뀌어 정보 수정 본인인증을 못 하는 경우
 * │              · 탈퇴 처리 — 이용정지 중이라 스스로 탈퇴할 수 없는 경우 등
 * │
 * │ 안 하는 일 가입 심사 결과(review_status)는 건드리지 않는다.
 * │            그건 /admin/applications 몫이다
 * └────────────────────────────────────────────────────────────────
 */

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\User;
use App\Support\Moderation;
use App\Support\Sms;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new
#[Layout('components.admin.layout', ['title' => '회원 관리'])]
class extends Component
{
    #[Locked]
    public int $userIdx;

    public string $reason = '';

    // 상호·대표자명 변경
    public string $company_name = '';
    public string $owner_name = '';
    public string $profile_reason = '';

    // 휴대폰·본인인증 초기화
    public string $new_phone = '';
    public string $identity_reason = '';

    // 탈퇴 처리
    public string $withdraw_reason = '';

    public ?string $notice = null;

    public function mount(User $user): void
    {
        $this->userIdx = $user->idx;
        $this->company_name = (string) $user->company_name;
        $this->owner_name = (string) $user->name;
    }

    public function with(): array
    {
        $user = User::findOrFail($this->userIdx);

        return [
            'member' => $user,
            'posts' => Post::where('user_idx', $user->idx)->orderByDesc('idx')->limit(30)->get(),
            'exposed' => Post::where('user_idx', $user->idx)->where('is_hidden', false)->count(),
            'logs' => AuditLog::where('target_type', 'user')
                ->where('target_idx', $user->idx)
                ->with('actor:idx,company_name')
                ->orderByDesc('occurred_at')
                ->limit(10)
                ->get(),
        ];
    }

    public function suspend(): void
    {
        $user = User::findOrFail($this->userIdx);

        if ($user->status === UserStatus::Suspended) {
            return;
        }

        // 관리자를 정지시키면 자기 발등을 찍는다.
        if ($user->isAdmin()) {
            $this->addError('reason', '관리자는 정지할 수 없습니다.');

            return;
        }

        $will_hide = Post::where('user_idx', $user->idx)->where('is_hidden', false)->count();

        Moderation::suspendUser($user, trim($this->reason) ?: null);

        $this->notice = $will_hide > 0
            ? "회원을 정지하고 노출 중이던 매물 {$will_hide}건을 함께 숨겼습니다."
            : '회원을 정지했습니다.';
        $this->reason = '';
    }

    public function reinstate(): void
    {
        $user = User::findOrFail($this->userIdx);

        if ($user->status !== UserStatus::Suspended) {
            return;
        }

        if (trim($this->reason) === '') {
            $this->addError('reason', '복구 사유를 적어 주세요.');

            return;
        }

        Moderation::reinstateUser($user, trim($this->reason));
        $this->notice = '정지를 해제했습니다. 정지 때문에 숨겼던 매물도 함께 되살렸습니다.';
        $this->reason = '';
    }

    /** 운영자 도구는 탈퇴한 회원에게는 쓰지 않는다. 관리자 계정은 탈퇴·초기화하지 않는다 */
    private function editable(bool $members_only = false): User
    {
        $user = User::findOrFail($this->userIdx);

        abort_if($user->status === UserStatus::Withdrawn, 403);
        abort_if($members_only && $user->isAdmin(), 403);

        return $user;
    }

    /** 상호·대표자명 변경. 사업자등록증이 바뀐 회원이 요청했을 때 */
    public function saveProfile(): void
    {
        $user = $this->editable();

        $this->validate([
            'company_name' => ['required', 'string', 'max:100'],
            'owner_name' => ['required', 'string', 'max:50'],
            'profile_reason' => ['required', 'string', 'max:255'],
        ], [], ['company_name' => '상호', 'owner_name' => '대표자명', 'profile_reason' => '변경 사유']);

        $before = $user->company_name.' / '.$user->name;

        DB::transaction(function () use ($user, $before) {
            $user->update(['company_name' => trim($this->company_name), 'name' => trim($this->owner_name)]);
            AuditLog::record('user.profile.changed', 'user', $user->idx,
                trim($this->profile_reason)." (변경 전: {$before})");
        });

        $this->profile_reason = '';
        $this->notice = '상호·대표자명을 바꿨습니다.';
    }

    /**
     * 휴대폰 번호를 바꾸고 본인인증(CI)을 비운다.
     * 회원이 새 번호로 정보 수정 본인인증을 하면 그때 새 CI 가 묶인다(account.edit 의 unlock).
     * 남의 계정을 가져가는 통로가 될 수 있으니, 본인 확인(사업자등록증·대표자 통화 등)을 마친 뒤에만 쓴다.
     */
    public function resetIdentity(): void
    {
        $user = $this->editable(members_only: true);
        $this->new_phone = preg_replace('/\D/', '', $this->new_phone);

        $this->validate([
            'new_phone' => ['required', 'regex:/^01[016789]\d{7,8}$/'],
            'identity_reason' => ['required', 'string', 'max:255'],
        ], ['new_phone.regex' => '휴대폰 번호를 다시 확인해 주세요.'], ['new_phone' => '새 휴대폰 번호', 'identity_reason' => '초기화 사유']);

        if (User::where('phone', $this->new_phone)->where('idx', '!=', $user->idx)->exists()) {
            $this->addError('new_phone', '다른 회원이 쓰고 있는 번호입니다.');

            return;
        }

        $old_phone = $user->phone;

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'phone' => $this->new_phone,
                'phone_verified_at' => null,
                'ci_hash' => null,
                'identity_verified_at' => null,
            ])->save();
            AuditLog::record('user.identity.reset', 'user', $user->idx, trim($this->identity_reason));
        });

        // 본인이 요청하지 않았다면 알아챌 수 있게 이전 번호로도 알린다
        if ($old_phone && $old_phone !== $this->new_phone) {
            Sms::send($old_phone, '[곳간] 운영자가 계정의 휴대폰 번호를 변경했습니다. 본인이 요청하지 않았다면 고객센터로 알려 주세요.');
        }

        $this->new_phone = '';
        $this->identity_reason = '';
        $this->notice = '휴대폰 번호를 바꾸고 본인인증을 초기화했습니다. 회원이 새 번호로 본인인증하면 정보 수정을 쓸 수 있습니다.';
    }

    /** 운영자가 탈퇴 처리. 정지 중이라 스스로 탈퇴할 수 없는 회원의 요청 등 */
    public function withdrawMember(): void
    {
        $user = $this->editable(members_only: true);

        $this->validate(['withdraw_reason' => ['required', 'string', 'max:255']], [], ['withdraw_reason' => '탈퇴 처리 사유']);

        $user->withdraw('user.withdrawn.by_admin', trim($this->withdraw_reason));

        $this->withdraw_reason = '';
        $this->notice = '탈퇴 처리했습니다. 개인정보를 지우고 매물을 모두 숨겼습니다.';
    }
}; ?>

<div>
    <div class="adm-head">
        <div>
            <a class="back" href="{{ route('admin.users') }}">{!! App\Support\Icon::render('back') !!}회원 관리</a>
            <h1>{{ $member->company_name }}</h1>
            <p class="sub">#{{ $member->idx }} · {{ $member->name }} · {{ $member->regionName() }}</p>
        </div>
        <div class="adm-head-actions">
            <a class="adm-btn" href="{{ route('admin.review', $member) }}">가입 심사</a>
        </div>
    </div>

    @if ($notice)
        <div class="adm-notice">{!! App\Support\Icon::render('check') !!}<span>{{ $notice }}</span></div>
    @endif

    <div class="adm-card">
        <div class="adm-card-head"><h2>현재 상태</h2></div>
        <div class="adm-card-body tight">
            <dl class="adm-facts">
                <dt>이용 상태</dt>
                <dd><span class="adm-badge {{ $member->status === App\Enums\UserStatus::Suspended ? 'off' : 'done' }}">{{ $member->status->label() }}</span></dd>
                <dt>심사 상태</dt>
                <dd><span class="adm-badge gray">{{ $member->review_status->label() }}</span></dd>
                <dt>매물</dt>
                <dd>전체 {{ $posts->count() }}건 · 노출 중 {{ $exposed }}건</dd>
                <dt>가입일</dt>
                <dd>{{ $member->created_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</dd>
            </dl>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card-head"><h2>{{ $member->status === App\Enums\UserStatus::Suspended ? '정지 해제' : '이용정지' }}</h2></div>
        <div class="adm-card-body">
            @if ($member->status === App\Enums\UserStatus::Suspended)
                <p class="sub" style="margin-bottom:12px">해제하면 <strong>정지 때문에 숨겨진 매물만</strong> 다시 노출됩니다. 판매자가 직접 내린 매물은 그대로 둡니다.</p>
            @else
                <p class="sub" style="margin-bottom:12px">정지하면 이미 로그인한 기기까지 로그아웃되고 <strong>노출 중인 매물 {{ $exposed }}건이 함께 숨겨집니다.</strong></p>
            @endif

            <div class="field" style="margin-bottom:12px">
                <label for="reason">{{ $member->status === App\Enums\UserStatus::Suspended ? '복구 사유' : '정지 사유' }}</label>
                <textarea id="reason" wire:model="reason" style="min-height:76px"
                          placeholder="{{ $member->status === App\Enums\UserStatus::Suspended ? '왜 정지를 푸는지 적어 주세요.' : '왜 정지하는지 적어 주세요.' }}"></textarea>
                @error('reason') <div class="error">{{ $message }}</div> @enderror
            </div>

            @if ($member->status === App\Enums\UserStatus::Suspended)
                <button type="button" class="adm-btn primary" wire:click="reinstate">정지 해제</button>
            @else
                <button type="button" class="adm-btn danger" wire:click="suspend">이용정지</button>
            @endif
        </div>
    </div>

    @unless ($member->status === App\Enums\UserStatus::Withdrawn)
        <div class="adm-card">
            <div class="adm-card-head"><h2>상호·대표자명 변경</h2></div>
            <div class="adm-card-body">
                <p class="sub" style="margin-bottom:12px">회원은 직접 바꿀 수 없습니다. 바뀐 사업자등록증을 확인한 뒤에만 바꿔 주세요.</p>
                <div class="field" style="margin-bottom:12px">
                    <label for="company_name">상호</label>
                    <input id="company_name" wire:model="company_name" maxlength="100">
                    @error('company_name') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field" style="margin-bottom:12px">
                    <label for="owner_name">대표자명</label>
                    <input id="owner_name" wire:model="owner_name" maxlength="50">
                    @error('owner_name') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field" style="margin-bottom:12px">
                    <label for="profile_reason">변경 사유</label>
                    <input id="profile_reason" wire:model="profile_reason" maxlength="255" placeholder="예: 상호 변경 사업자등록증 확인(2026-10-01)">
                    @error('profile_reason') <div class="error">{{ $message }}</div> @enderror
                </div>
                <button type="button" class="adm-btn primary" wire:click="saveProfile">변경 저장</button>
            </div>
        </div>

        @unless ($member->isAdmin())
            <div class="adm-card">
                <div class="adm-card-head"><h2>휴대폰·본인인증 초기화</h2></div>
                <div class="adm-card-body">
                    <p class="sub" style="margin-bottom:12px">
                        지금 번호: {{ $member->formattedPhone() ?: '-' }} · 본인인증 {{ $member->ci_hash ? '연결됨' : '없음' }}<br>
                        번호가 바뀌어 정보 수정 본인인증을 못 하는 회원에게 씁니다. <strong>대표자 본인 확인을 마친 뒤에만</strong> 쓰세요.
                        새 번호로 바꾸고 본인인증을 비우면, 회원이 새 번호로 인증할 때 다시 연결됩니다. 이전 번호로 안내 문자가 갑니다.
                    </p>
                    <div class="field" style="margin-bottom:12px">
                        <label for="new_phone">새 휴대폰 번호</label>
                        <input id="new_phone" wire:model="new_phone" inputmode="tel" placeholder="010-1234-5678">
                        @error('new_phone') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field" style="margin-bottom:12px">
                        <label for="identity_reason">초기화 사유</label>
                        <input id="identity_reason" wire:model="identity_reason" maxlength="255" placeholder="예: 번호 변경, 대표자 통화로 본인 확인">
                        @error('identity_reason') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <button type="button" class="adm-btn danger" wire:click="resetIdentity"
                            wire:confirm="휴대폰 번호를 바꾸고 본인인증을 초기화할까요?">초기화</button>
                </div>
            </div>

            <div class="adm-card">
                <div class="adm-card-head"><h2>탈퇴 처리</h2></div>
                <div class="adm-card-body">
                    <p class="sub" style="margin-bottom:12px">회원이 스스로 탈퇴할 수 없을 때(이용정지 중 등) 요청을 받아 처리합니다. 개인정보를 지우고 매물을 모두 숨기며 <strong>되돌릴 수 없습니다.</strong></p>
                    <div class="field" style="margin-bottom:12px">
                        <label for="withdraw_reason">탈퇴 처리 사유</label>
                        <input id="withdraw_reason" wire:model="withdraw_reason" maxlength="255" placeholder="예: 정지 회원 탈퇴 요청(전화, 2026-10-01)">
                        @error('withdraw_reason') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <button type="button" class="adm-btn danger" wire:click="withdrawMember"
                            wire:confirm="탈퇴 처리하면 되돌릴 수 없습니다. 계속할까요?">탈퇴 처리</button>
                </div>
            </div>
        @endunless
    @endunless

    @if ($posts->isNotEmpty())
        <div class="adm-card">
            <div class="adm-card-head"><h2>이 회원의 매물</h2></div>
            <div class="adm-card-body tight">
                <div class="adm-scroll">
                    <table class="adm-table">
                        <thead><tr><th>번호</th><th>제목</th><th>노출</th><th>판매 상태</th><th>등록일</th><th>관리</th></tr></thead>
                        <tbody>
                        @foreach ($posts as $post)
                            <tr @class(['off' => $post->is_hidden])>
                                <td>#{{ $post->idx }}</td>
                                <td>{{ $post->title }}</td>
                                <td><span class="adm-badge {{ $post->is_hidden ? 'off' : 'done' }}">{{ $post->is_hidden ? '숨김' : '노출' }}</span></td>
                                <td><span class="adm-badge gray">{{ $post->status->label($post->post_type) }}</span></td>
                                <td class="muted">{{ $post->created_at?->timezone('Asia/Seoul')->format('Y-m-d') }}</td>
                                <td><a class="adm-btn tiny" href="{{ route('admin.post', $post) }}">열기</a></td>
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
