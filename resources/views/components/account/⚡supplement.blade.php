<?php

use App\Enums\ApplicationStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\PicksRegion;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * 자료 보완 후 재제출. 관리자가 '보완 요청'을 한 회원만 들어온다.
 * 사업자등록번호(로그인 아이디)와 휴대폰(문자 인증 필요)은 여기서 바꾸지 않는다.
 * 제출하면 심사중으로 돌아가고 관리자 목록 맨 위에 다시 올라간다.
 */
new class extends Component
{
    use PicksRegion;

    use WithFileUploads;

    public string $company_name = '';
    public string $owner_name = '';
    public string $industry = '';
    public string $email = '';

    /** 새 사업자등록증. 비워 두면 기존 파일을 그대로 쓴다. */
    public $license = null;

    public function mount()
    {
        $user = auth()->user();

        if ($user->review_status !== ApplicationStatus::Supplement) {
            return $this->redirectRoute('account.index');
        }

        $this->company_name = $user->company_name;
        $this->owner_name = $user->name;
        $this->industry = (string) $user->industry;
        $this->region_code = (string) $user->region_code;
        $this->region_name = (string) $user->region_name;
        $this->email = (string) $user->email;
    }

    public function with(): array
    {
        return [
            'reason' => auth()->user()->reviews()->first()?->reason,
        ];
    }

    public function submit()
    {
        $this->validate([
            'company_name' => ['required', 'string', 'max:100'],
            'owner_name' => ['required', 'string', 'max:50'],
            'email' => User::emailRules(),
            'industry' => ['nullable', 'string', 'max:50'],
            'license' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ] + $this->regionRules(keep_old: true), User::emailMessages() + $this->regionMessages(), [
            'company_name' => '상호', 'owner_name' => '대표자명', 'email' => '이메일',
            'industry' => '업종', 'region_code' => '사업장 지역', 'license' => '사업자등록증',
        ]);

        $user = auth()->user();

        $applied = DB::transaction(function () use ($user) {
            // 보완요청 상태일 때만 바꾼다. 화면을 열어 둔 사이 관리자가 먼저 처리했으면 0건이다.
            // lock_version 을 올려서, 관리자가 재제출 전에 열어 둔 심사 화면으로는 처리하지 못하게 한다.
            $affected = User::where('idx', $user->idx)
                ->where('review_status', ApplicationStatus::Supplement->value)
                ->update([
                    'company_name' => $this->company_name,
                    'name' => $this->owner_name,
                    'industry' => $this->industry ?: null,
                    'region_code' => $this->region_code,
                    'region_name' => $this->region_name,
                    'email' => $this->email ?: null,
                    'review_status' => ApplicationStatus::Reviewing->value,
                    'submitted_at' => now(),
                    'lock_version' => DB::raw('lock_version + 1'),
                ]);

            if ($affected === 0) {
                return false;
            }

            // 파일은 DB 를 바꾼 뒤에 덮어쓴다. 저장에 실패하면 예외로 트랜잭션이 되돌아간다.
            if ($this->license) {
                $this->license->storeAs('licenses', (string) $user->idx, 'local');
            }

            AuditLog::record('application.resubmitted', 'user', $user->idx);

            return true;
        });

        if (! $applied) {
            return redirect()->route('account.index')
                ->with('notice', '이미 심사가 진행된 신청입니다. 현재 상태를 확인해 주세요.');
        }

        return redirect()->route('account.index')
            ->with('notice', '보완 자료를 제출했습니다. 다시 심사한 뒤 결과를 문자로 알려드립니다.');
    }
}; ?>

<div class="narrow">
    <div class="page-head">
        <div>
            <h1>자료 보완</h1>
            <p class="sub">{{ auth()->user()->bizNo() }} · 사업자등록번호와 휴대폰 번호는 여기서 바꿀 수 없습니다.</p>
        </div>
    </div>

    <form class="form-shell" wire:submit="submit">
        <div class="form-inner">
            <div class="field">
                <label>보완 요청 사유</label>
                <p class="note">{{ $reason ?: '사유가 기록되지 않았습니다.' }}</p>
            </div>

            <div class="field">
                <label for="company">상호 (업체명)</label>
                <input id="company" wire:model="company_name">
                <small>사업자등록증에 적힌 상호와 같아야 합니다.</small>
                @error('company_name') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="owner">대표자명</label>
                <input id="owner" wire:model="owner_name">
                @error('owner_name') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="industry">업종</label>
                <input id="industry" wire:model="industry" maxlength="50">
                @error('industry') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label>사업장 지역</label>
                <x-address-search :name="$region_name" hint="도로명이나 건물 이름으로 찾아 주세요. 읍·면·동까지만 저장하고 상세 주소는 저장하지 않습니다. 다른 회원에게는 시·군·구까지만 보이고, 재고 등록 때 기본 주소로 쓰입니다." />
                @error('region_code') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label>이메일 <span class="dim">(선택)</span></label>
                <x-email-input model="email" />
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="license">사업자등록증 <span class="dim">(바꿀 때만)</span></label>
                <input id="license" type="file" wire:model="license" accept=".jpg,.jpeg,.png,.pdf">
                <small>새로 올리면 기존 파일을 대신합니다. 올리지 않으면 처음 낸 파일로 다시 심사합니다. JPG · PNG · PDF, 10MB 이하.</small>
                <div wire:loading wire:target="license"><p class="note">파일을 올리는 중입니다…</p></div>
                @error('license') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="form-footer">
            <a class="btn" href="{{ route('account.index') }}">취소</a>
            <button type="submit" class="btn primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">다시 제출</span>
                <span wire:loading wire:target="submit">제출하는 중…</span>
            </button>
        </div>
    </form>
</div>
