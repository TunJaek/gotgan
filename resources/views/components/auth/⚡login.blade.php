<?php

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\BizNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

new class extends Component
{
    public string $bizNumber = '';
    public string $password = '';
    public bool $remember = false;

    public function submit()
    {
        $this->validate([
            'bizNumber' => ['required'],
            'password' => ['required'],
        ], [], ['bizNumber' => '사업자등록번호', 'password' => '비밀번호']);

        // 같은 번호로 반복 시도하는 것을 막는다. 캐시 키에 번호를 그대로 남기지 않으려고 한 번 접어서 쓴다.
        $key = 'login:'.hash('sha256', BizNumber::normalize($this->bizNumber)).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('bizNumber', RateLimiter::availableIn($key).'초 뒤에 다시 시도해 주세요.');

            return;
        }

        $found = User::where('biz_no', BizNumber::normalize($this->bizNumber))->first();

        // 계정이 없어도 같은 경로를 타게 해서 응답 시간으로 가입 여부를 알 수 없게 한다.
        $userIdx = $found?->idx ?? 0;   // 없는 계정도 같은 경로를 타게 한다

        if (! Auth::attempt(['idx' => $userIdx, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, 300);
            $this->addError('bizNumber', '사업자등록번호 또는 비밀번호가 맞지 않습니다.');

            return;
        }

        if (auth()->user()->status === UserStatus::Suspended) {
            Auth::logout();
            $this->addError('bizNumber', '이용이 정지된 계정입니다. 문의해 주세요.');

            return;
        }

        RateLimiter::clear($key);
        session()->regenerate();

        return redirect()->intended(route('market.index'));
    }
}; ?>

<div class="narrow">
    <div class="page-head">
        <div>
            <h1>로그인</h1>
            <p class="sub">사업자등록번호가 아이디입니다.</p>
        </div>
    </div>

    <form class="form-shell" wire:submit="submit">
        <div class="form-inner">
            {{-- 비밀번호 찾기 등에서 돌아올 때 남긴 안내 --}}
            @if (session('notice'))
                <p class="note">{{ session('notice') }}</p>
            @endif

            <div class="field">
                <label for="biz">사업자등록번호</label>
                <input id="biz" wire:model="bizNumber" placeholder="123-45-67891" inputmode="numeric" autofocus>
                @error('bizNumber') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="pw">비밀번호</label>
                <input id="pw" type="password" wire:model="password" autocomplete="current-password">
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>

            <label class="check-line">
                <input type="checkbox" wire:model="remember">
                <span>로그인 상태 유지</span>
            </label>
        </div>

        <div class="form-footer">
            <button type="submit" class="btn primary full">로그인</button>
        </div>
    </form>

    <p class="sub" style="margin-top:16px;text-align:center">
        <a href="{{ route('password.find') }}">비밀번호를 잊으셨나요?</a>
        · 아직 회원이 아니신가요? <a href="{{ route('register') }}">회원 가입</a>
    </p>

    {{-- 비회원이 하단 메뉴 '내 정보'를 누르면 여기로 온다. 휴대폰에서도 약관에 닿게 둔다(2026-09-30) --}}
    <p class="legal">
        <a href="{{ route('terms') }}">이용약관</a> · <a href="{{ route('privacy') }}"><strong>개인정보처리방침</strong></a>
    </p>
</div>
