<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\BizNumber;
use App\Support\Pass;
use App\Support\Sms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * 비밀번호 찾기. ① 휴대폰 본인인증(PASS) → ② 사업자등록번호 + 새 비밀번호.
 * 가입 때 본인인증한 사람과 CI 가 같아야 한다(번호가 바뀌어도 본인이면 찾을 수 있다).
 * CI 가 없는 계정은 이 화면으로 찾을 수 없다. 번호만 같은지로 확인하면, 인증창에서 번호를 바꿔
 * 인증할 수 있을 때 남의 계정을 가져갈 수 있어서 뺐다(2026-09-29). 운영자에게 문의하게 한다.
 * 가입 여부가 드러나지 않게, 맞지 않으면 어느 쪽이 틀렸는지 말하지 않는다.
 */
new class extends Component
{
    public string $biz_number = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function with(): array
    {
        return ['pass' => Pass::result('reset')];
    }

    public function resetPassword()
    {
        $this->validate(
            ['biz_number' => ['required'], 'password' => User::passwordRules('password_confirmation')],
            User::passwordMessages(),
            ['biz_number' => '사업자등록번호'],
        );

        // 본인인증 하나로 사업자번호를 여러 개 넣어 보는 것을 막는다
        $key = 'reset:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('biz_number', '시도가 많습니다. '.ceil(RateLimiter::availableIn($key) / 60).'분 뒤에 다시 해 주세요.');

            return;
        }
        RateLimiter::hit($key, 600);

        // 화면 상태는 믿지 않는다. 세션의 본인인증 결과(10분)를 다시 본다.
        $pass = Pass::result('reset');

        if (! $pass) {
            $this->addError('biz_number', '본인인증이 끝나지 않았거나 10분이 지났습니다. 다시 인증해 주세요.');

            return;
        }

        $user = User::where('biz_no', BizNumber::normalize($this->biz_number))->first();

        $same_person = $user && $user->ci_hash && hash_equals($user->ci_hash, $pass['ci_hash']);

        if (! $same_person) {
            $this->addError('biz_number', '사업자등록번호와 본인인증 정보가 맞지 않습니다. 가입할 때 본인인증을 하지 않은 계정이라면 운영자에게 문의해 주세요.');

            return;
        }

        DB::transaction(function () use ($user, $pass) {
            $user->password = $this->password;
            $user->setRememberToken(Str::random(60));   // '로그인 상태 유지'로 남은 로그인도 끊는다

            $user->save();

            // 다른 기기에 남아 있는 로그인을 모두 끊는다(SESSION_DRIVER=database)
            DB::table('sessions')->where('user_id', $user->idx)->delete();

            AuditLog::record('user.password.reset', 'user', $user->idx);
        });

        Pass::result('reset', consume: true);   // 같은 인증으로 두 번 바꿀 수 없다
        RateLimiter::clear($key);
        RateLimiter::clear('login:'.hash('sha256', $user->biz_no).'|'.request()->ip());

        // 본인이 바꾸지 않았다면 바로 알아챌 수 있게 알린다. 발송 실패가 변경을 되돌리지는 않는다.
        Sms::send($user->phone, '[곳간] 비밀번호가 변경되었습니다. 본인이 바꾸지 않았다면 운영자에게 알려 주세요.');

        return redirect()->route('login')->with('notice', '비밀번호를 바꿨습니다. 새 비밀번호로 로그인해 주세요.');
    }
}; ?>

<div class="narrow">
    <div class="page-head">
        <div>
            <h1>비밀번호 찾기</h1>
            <p class="sub">휴대폰 본인인증으로 본인을 확인한 뒤 새 비밀번호를 정합니다.</p>
        </div>
    </div>

    @if (session('notice'))
        <p class="note">{{ session('notice') }}</p>
    @endif

    @if (! $pass)
        <form class="form-shell" method="post" action="{{ route('pass.start', 'reset') }}">
            @csrf
            <div class="form-inner">
            <h2>1. 휴대폰 본인인증</h2>
            <p style="margin-top:12px">가입할 때 본인인증한 사람과 같은 사람인지 확인합니다.</p>
            <div class="field" style="margin-top:16px">
                <label for="pass-phone">현재 사용하는 휴대폰 번호</label>
                <input id="pass-phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="010-1234-5678" required>
                <small>가입 뒤 번호가 바뀐어도 본인이 맞으면 찾을 수 있습니다.</small>
            </div>
            </div>
            <div class="form-footer"><button class="btn primary full" type="submit">휴대폰 본인인증</button></div>
        </form>
    @else
        <form class="form-shell" wire:submit="resetPassword">
            <div class="form-inner">
                <p class="note" style="margin-top:0">본인인증을 마쳤습니다. 10분 안에 비밀번호를 바꿔 주세요.</p>

                <div class="field">
                    <label for="biz">사업자등록번호</label>
                    <input id="biz" wire:model="biz_number" placeholder="123-45-67891" inputmode="numeric" autofocus>
                    @error('biz_number') <p class="error">{{ $message }}</p> @enderror
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
            </div>
            <div class="form-footer">
                <button type="submit" class="btn primary full" wire:loading.attr="disabled">비밀번호 바꾸기</button>
            </div>
        </form>
    @endif

    <p class="sub" style="margin-top:16px;text-align:center">
        <a href="{{ route('login') }}">로그인으로 돌아가기</a>
    </p>
</div>
