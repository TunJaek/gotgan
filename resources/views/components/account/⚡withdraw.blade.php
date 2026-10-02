<?php

use App\Enums\UserStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

/**
 * 회원 탈퇴. 비밀번호를 다시 넣어 본인을 확인하고 User::withdraw() 를 부른다.
 * 즉시 처리하고 되돌릴 수 없다. 관리자와 이용정지 회원은 탈퇴할 수 없다.
 *
 * '탈퇴하기' → ask() 가 비밀번호·체크를 확인하고 "정말 탈퇴하시겠습니까?" 모달을 연다(2026-09-29)
 * → 모달의 '탈퇴하기' → withdraw() 가 같은 확인을 다시 하고 처리한다(모달 상태는 믿지 않는다).
 */
new class extends Component
{
    public string $password = '';
    public bool $confirmed = false;

    /** 마지막 확인 모달이 열려 있는가 */
    public bool $asking = false;

    public function mount(): void
    {
        abort_if(auth()->user()->isAdmin(), 403);
    }

    /** 폼의 '탈퇴하기'. 확인이 모두 맞으면 마지막 확인 모달을 연다 */
    public function ask(): void
    {
        $this->asking = $this->checks();
    }

    public function cancel(): void
    {
        $this->asking = false;
    }

    public function withdraw()
    {
        $this->asking = false;

        if (! $this->checks()) {
            return;
        }

        $user = auth()->user();
        $user->withdraw();

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login')->with('notice', '탈퇴했습니다. 그동안 곳간을 이용해 주셔서 감사합니다.');
    }

    /** 탈퇴해도 되는가. 안 되면 이유를 오류로 남기고 false */
    private function checks(): bool
    {
        $user = auth()->user();
        abort_if($user->isAdmin(), 403);

        // 정지를 피하려고 탈퇴 후 다시 가입하는 것을 막는다
        if ($user->status === UserStatus::Suspended) {
            $this->addError('password', '이용정지 중에는 탈퇴할 수 없습니다. 운영자에게 문의해 주세요.');

            return false;
        }

        $this->validate([
            'password' => ['required'],
            'confirmed' => ['accepted'],
        ], [
            'password.required' => '비밀번호를 입력해 주세요.',
            'confirmed.accepted' => '안내 내용을 확인했다고 체크해 주세요.',
        ]);

        if (! Hash::check($this->password, $user->password)) {
            $this->addError('password', '비밀번호가 맞지 않습니다.');

            return false;
        }

        return true;
    }
}; ?>

<div class="narrow">
    <div class="page-head">
        <div>
            <h1>회원 탈퇴</h1>
            <p class="sub">{{ auth()->user()->company_name }} · {{ auth()->user()->bizNo() }}</p>
        </div>
    </div>

    @if (auth()->user()->status === UserStatus::Suspended)
        <section class="panel">
            <p>이용정지 중에는 탈퇴할 수 없습니다. 운영자에게 문의해 주세요.</p>
            <a class="btn" href="{{ route('account.index') }}" style="margin-top:14px">내 정보로 돌아가기</a>
        </section>
    @else
        <form class="form-shell" wire:submit="ask">
            <div class="form-inner">
                <div class="field">
                    <label>탈퇴하면</label>
                    <ul>
                        <li><small>즉시 처리되며 <strong>되돌릴 수 없습니다.</strong></small></li>
                        <li><small>상호·대표자명·휴대폰·이메일 등 회원 정보와 사업자등록증 파일을 지웁니다.</small></li>
                        <li><small>올린 매물은 모두 다른 회원에게 보이지 않게 되고, 연락처 공개도 꺼집니다.</small></li>
                        <li><small>주고받은 문의 대화는 상대방 화면에 남으며, 보낸 사람은 '탈퇴한 회원'으로 표시됩니다.</small></li>
                        <li><small>신고·분쟁 처리에 필요한 기록은 개인정보처리방침에 정한 기간 동안 보관합니다.</small></li>
                        <li><small>같은 사업자등록번호로 다시 가입할 수 있지만, 예전 매물과 대화는 이어지지 않습니다.</small></li>
                    </ul>
                </div>

                <div class="field">
                    <label for="pw">비밀번호 확인</label>
                    <input id="pw" type="password" wire:model="password" autocomplete="current-password">
                    @error('password') <p class="error">{{ $message }}</p> @enderror
                </div>

                <label class="check-line">
                    <input type="checkbox" wire:model="confirmed">
                    <span>위 내용을 확인했으며 탈퇴합니다.</span>
                </label>
                @error('confirmed') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="form-footer">
                <a class="btn" href="{{ route('account.index') }}">취소</a>
                <button type="submit" class="btn primary" wire:loading.attr="disabled">탈퇴하기</button>
            </div>
        </form>

        {{-- 마지막 확인. ESC 는 취소와 같다. --}}
        @if ($asking)
            <dialog x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.cancel()">
                <div class="dialog-header">
                    <h2>정말 탈퇴하시겠습니까?</h2>
                </div>
                <div class="dialog-body">
                    <p><strong>{{ auth()->user()->company_name }}</strong> 계정을 지금 바로 탈퇴합니다.</p>
                    <p class="note warning">회원 정보와 사업자등록증이 지워지고, 올린 매물은 모두 숨겨집니다. <strong>되돌릴 수 없습니다.</strong></p>
                </div>
                <div class="dialog-footer">
                    <button type="button" class="btn" style="flex:1" wire:click="cancel">취소</button>
                    <button type="button" class="btn danger" style="flex:1" wire:click="withdraw" wire:loading.attr="disabled">탈퇴하기</button>
                </div>
            </dialog>
        @endif
    @endif
</div>
