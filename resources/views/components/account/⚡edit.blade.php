<?php

use App\Models\AuditLog;
use App\Models\ContactConsent;
use App\Models\Post;
use App\Models\User;
use App\Support\Pass;
use App\Support\Sms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Support\PicksRegion;
use Livewire\Component;

/**
 * 내 정보 수정. ① 이메일·업종·지역 ② 휴대폰 번호(본인인증) ③ 비밀번호.
 * 상호·대표자명은 사업자등록증과 같아야 해서 여기서 바꾸지 않는다(승인 뒤 사칭 방지, 2026-09-28).
 *
 * 들어오면 먼저 가입한 번호로 본인인증을 해야 30분 동안 고칠 수 있다(2026-09-29).
 * 인증한 번호나 사람(CI)이 가입 정보와 다르면 고객센터로 안내하고 내보낸다.
 */
new class extends Component
{
    use PicksRegion;

    /** 본인인증 뒤 수정 화면을 열어 두는 시간(분) */
    private const UNLOCK_MINUTES = 30;

    // ① 기본 정보
    public string $email = '';
    public string $industry = '';
    public string $basic_notice = '';

    // ② 휴대폰
    public string $phone_notice = '';

    // ③ 비밀번호
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $password_notice = '';

    public function mount(): void
    {
        $user = auth()->user();

        // 방금 끝난 입장용 본인인증이 있으면 확인한다. 결과는 한 번만 쓴다.
        if ($pass = Pass::result('edit', consume: true)) {
            if (! $this->unlock($user, $pass)) {
                session()->forget('account.unlocked');
                session()->flash('notice', '본인인증 정보가 가입 정보와 다릅니다. 고객센터로 문의해 주세요.');
                $this->redirectRoute('account.index');

                return;
            }
        }

        $this->email = (string) $user->email;
        $this->industry = (string) $user->industry;
        $this->region_code = (string) $user->region_code;
        $this->region_name = (string) $user->region_name;
    }

    public function with(): array
    {
        return [
            'user' => auth()->user(),
            'unlocked' => $this->unlocked(),
            'pass' => Pass::result('phone'),   // 휴대폰 변경용 본인인증 결과(10분)
        ];
    }

    // ── 입장 본인인증 ───────────────────────────────────

    /**
     * 가입한 번호·가입한 사람(CI)과 같으면 잠금을 푼다.
     * 본인인증 도입 전 계정(CI 없음)은 번호가 같을 때만 이번 CI 를 묶는다. 다른 계정의 CI 면 묶지 않는다.
     */
    private function unlock(User $user, array $pass): bool
    {
        if ($pass['phone'] !== $user->phone) {
            return false;
        }

        if ($user->ci_hash) {
            if (! hash_equals($user->ci_hash, $pass['ci_hash'])) {
                return false;
            }
        } else {
            if (User::where('ci_hash', $pass['ci_hash'])->exists()) {
                return false;
            }
            $user->update(['ci_hash' => $pass['ci_hash'], 'identity_verified_at' => now()]);
        }

        session()->put('account.unlocked', ['idx' => $user->idx, 'at' => now()->timestamp]);

        return true;
    }

    private function unlocked(): bool
    {
        $unlocked = session('account.unlocked');

        return $unlocked
            && $unlocked['idx'] === auth()->id()
            && $unlocked['at'] >= now()->subMinutes(self::UNLOCK_MINUTES)->timestamp;
    }

    /** 저장 직전에 다시 본다. 시간이 지났으면 인증 화면으로 돌려보낸다. */
    private function locked(): bool
    {
        if ($this->unlocked()) {
            return false;
        }

        session()->flash('notice', '본인인증 후 '.self::UNLOCK_MINUTES.'분이 지났습니다. 다시 인증해 주세요.');
        $this->redirectRoute('account.edit');

        return true;
    }

    // ── ① 기본 정보 ─────────────────────────────────────

    public function saveBasic(): void
    {
        if ($this->locked()) {
            return;
        }

        $this->validate([
            'email' => User::emailRules(),
            'industry' => ['nullable', 'string', 'max:50'],
        ] + $this->regionRules(keep_old: true), User::emailMessages() + $this->regionMessages(), ['email' => '이메일', 'industry' => '업종']);

        auth()->user()->update([
            'email' => $this->email ?: null,
            'industry' => $this->industry ?: null,
            'region_code' => $this->region_code,
            'region_name' => $this->region_name,
        ]);

        $this->basic_notice = '저장했습니다.';
    }

    // ── ② 휴대폰 번호 ───────────────────────────────────

    /**
     * 본인인증으로 확인한 번호로 바꾼다. 가입 때 본인인증한 사람과 같은 사람(CI)이어야 한다.
     * 본인인증 도입 전 회원은 이번 인증으로 CI 를 묶는다.
     */
    public function changePhone(): void
    {
        if ($this->locked()) {
            return;
        }

        // 화면 상태는 믿지 않는다. 세션의 본인인증 결과(10분)를 다시 본다.
        $pass = Pass::result('phone');

        if (! $pass) {
            $this->addError('phone', '본인인증이 끝나지 않았거나 10분이 지났습니다. 다시 인증해 주세요.');

            return;
        }

        $user = auth()->user();

        if (! $user->ci_hash || ! hash_equals($user->ci_hash, $pass['ci_hash'])) {
            Pass::result('phone', consume: true);
            $this->addError('phone', '가입할 때 본인인증한 사람과 다른 사람입니다. 계정 주인 본인이 인증해 주세요.');

            return;
        }

        if ($pass['phone'] === $user->phone) {
            Pass::result('phone', consume: true);
            $this->addError('phone', '지금 쓰고 있는 번호와 같습니다.');

            return;
        }

        $old_phone = $user->phone;

        $hidden = DB::transaction(function () use ($user, $pass) {
            $user->update([
                'phone' => $pass['phone'],
                'phone_verified_at' => now(),
                'identity_verified_at' => now(),
            ]);

            // 동의할 때와 다른 번호가 공개되면 안 된다. 공개 중인 매물을 비공개로 돌리고 동의에 철회를 찍는다.
            $posts = Post::where('user_idx', $user->idx)->where('show_phone', true)->get();
            foreach ($posts as $post) {
                $post->update(['show_phone' => false]);
                ContactConsent::syncFor($post);
            }

            AuditLog::record('user.phone.changed', 'user', $user->idx);

            return $posts->count();
        });

        Pass::result('phone', consume: true);   // 같은 인증으로 두 번 바꿀 수 없다

        // 도용으로 번호가 바뀐 경우 원래 주인이 알아챌 수 있게 이전 번호로 알린다
        Sms::send($old_phone, '[곳간] 휴대폰 번호가 변경되었습니다. 본인이 바꾸지 않았다면 운영자에게 알려 주세요.');

        $this->phone_notice = $hidden > 0
            ? "휴대폰 번호를 바꿨습니다. 연락처를 공개하던 매물 {$hidden}건은 비공개로 바꿨습니다. 새 번호로 공개하려면 매물 수정에서 다시 동의해 주세요."
            : '휴대폰 번호를 바꿨습니다.';
    }

    // ── ③ 비밀번호 ──────────────────────────────────────

    public function changePassword(): void
    {
        if ($this->locked()) {
            return;
        }

        $this->validate(
            ['current_password' => ['required'], 'password' => User::passwordRules('password_confirmation')],
            User::passwordMessages(),
            ['current_password' => '현재 비밀번호'],
        );

        $user = auth()->user();

        if (! Hash::check($this->current_password, $user->password)) {
            $this->addError('current_password', '현재 비밀번호가 맞지 않습니다.');

            return;
        }

        DB::transaction(function () use ($user) {
            $user->password = $this->password;
            $user->setRememberToken(Str::random(60));   // 다른 기기의 '로그인 상태 유지'도 끊는다
            $user->save();

            // 지금 이 기기는 로그인을 유지하고, 다른 기기의 로그인만 끊는다
            DB::table('sessions')->where('user_id', $user->idx)->where('id', '!=', session()->getId())->delete();

            AuditLog::record('user.password.changed', 'user', $user->idx);
        });

        Sms::send($user->phone, '[곳간] 비밀번호가 변경되었습니다. 본인이 바꾸지 않았다면 운영자에게 알려 주세요.');

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->password_notice = '비밀번호를 바꿨습니다. 다른 기기의 로그인은 모두 해제했습니다.';
    }
}; ?>

<div class="narrow">
    <a class="back" href="{{ route('account.index') }}">{!! App\Support\Icon::render('back') !!}내 정보</a>

    <div class="page-head">
        <div>
            <h1>정보 수정</h1>
            <p class="sub">{{ $user->company_name }} · {{ $user->bizNo() }}</p>
        </div>
    </div>

    @if (session('notice'))
        <p class="note">{{ session('notice') }}</p>
    @endif

    @if (! $unlocked)
    {{-- 입장 본인인증: 가입한 번호로만 --}}
    <section class="form-shell">
        <div class="form-inner">
            <h2 style="margin-bottom:16px">본인 확인</h2>
            <p class="sub" style="margin-bottom:14px">정보를 바꾸려면 먼저 가입한 휴대폰 번호({{ $user->formattedPhone() }})로 본인인증을 해 주세요. 인증하면 30분 동안 수정할 수 있습니다.</p>
            <form method="post" action="{{ route('pass.start', 'edit') }}">
                @csrf
                <button type="submit" class="btn primary full">휴대폰 본인인증</button>
            </form>
            <small style="display:block;margin-top:12px">휴대폰 번호가 바뀌어 이 번호로 인증할 수 없다면 고객센터로 문의해 주세요.</small>
        </div>
    </section>
    @else
    {{-- ① 기본 정보 --}}
    <form class="form-shell" wire:submit="saveBasic">
        <div class="form-inner">
            <h2 style="margin-bottom:16px">기본 정보</h2>

            <div class="field">
                <label>상호 · 대표자명</label>
                <p>{{ $user->company_name }} · {{ $user->name }}</p>
                <small>사업자등록증과 같아야 해서 여기서 바꿀 수 없습니다. 바뀌었다면 운영자에게 문의해 주세요.</small>
            </div>

            <div class="field">
                <label>이메일 <span class="dim">(선택)</span></label>
                <x-email-input model="email" />
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="industry">업종 <span class="dim">(선택)</span></label>
                <input id="industry" wire:model="industry" maxlength="50">
                @error('industry') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label>사업장 지역</label>
                <x-address-search :name="$region_name" hint="도로명이나 건물 이름으로 찾아 주세요. 읍·면·동까지만 저장하고 상세 주소는 저장하지 않습니다. 다른 회원에게는 시·군·구까지만 보이고, 재고 등록 때 기본 주소로 쓰입니다." />
                @error('region_code') <p class="error">{{ $message }}</p> @enderror
            </div>

            @if ($basic_notice) <p class="note">{{ $basic_notice }}</p> @endif
        </div>
        <div class="form-footer">
            <button type="submit" class="btn primary full">기본 정보 저장</button>
        </div>
    </form>

    {{-- ② 휴대폰 번호: 본인인증(다날 PASS)으로 확인한 번호로 바꾼다 --}}
    <section class="form-shell" style="margin-top:18px">
        <div class="form-inner">
            <h2 style="margin-bottom:16px">휴대폰 번호</h2>
            <p class="sub" style="margin-bottom:14px">지금 번호: {{ $user->formattedPhone() }}</p>

            @if ($pass)
                <p>인증한 새 번호: <strong>{{ preg_replace('/^(\d{3})(\d{3,4})(\d{4})$/', '$1-$2-$3', $pass['phone']) }}</strong> <span class="badge">본인인증 완료</span></p>
                <button type="button" class="btn primary full" style="margin-top:14px" wire:click="changePhone">이 번호로 바꾸기</button>
            @else
                <form method="post" action="{{ route('pass.start', 'phone') }}">
                    @csrf
                    <div class="field">
                        <label for="new-phone">새 휴대폰 번호</label>
                        <input id="new-phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="010-1234-5678" required>
                    </div>
                    <button type="submit" class="btn full">휴대폰 본인인증으로 번호 바꾸기</button>
                </form>
            @endif
            @error('phone') <p class="error">{{ $message }}</p> @enderror

            <small style="display:block;margin-top:12px">가입할 때 본인인증한 사람과 같은 사람만 바꿀 수 있습니다. 번호를 바꾸면 연락처를 공개하던 매물은 모두 비공개로 바뀌고, 이전 번호로 변경 안내 문자가 갑니다.</small>
            @if ($phone_notice) <p class="note">{{ $phone_notice }}</p> @endif
        </div>
    </section>

    {{-- ③ 비밀번호 --}}
    <form class="form-shell" style="margin-top:18px" wire:submit="changePassword">
        <div class="form-inner">
            <h2 style="margin-bottom:16px">비밀번호</h2>

            <div class="field">
                <label for="current_password">현재 비밀번호</label>
                <input id="current_password" type="password" wire:model="current_password" autocomplete="current-password">
                @error('current_password') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="pw">새 비밀번호</label>
                <input id="pw" type="password" wire:model="password" autocomplete="new-password">
                <small>8자 이상 · 대문자·소문자·숫자·특수기호를 각각 하나 이상</small>
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="pw2">새 비밀번호 확인</label>
                <input id="pw2" type="password" wire:model="password_confirmation" autocomplete="new-password">
            </div>

            <small>바꾸면 지금 이 기기를 뺀 다른 기기의 로그인이 모두 해제되고, 변경 안내 문자가 갑니다.</small>
            @if ($password_notice) <p class="note">{{ $password_notice }}</p> @endif
        </div>
        <div class="form-footer">
            <button type="submit" class="btn primary full">비밀번호 변경</button>
        </div>
    </form>
    @endif
</div>
