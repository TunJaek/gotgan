<?php

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Agreement;
use App\Models\User;
use App\Support\BiznoStatus;
use App\Support\BizNumber;
use App\Support\Pass;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Support\PicksRegion;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use PicksRegion;

    use WithFileUploads;

    public string $bizNumber = '';
    public string $password = '';
    public string $passwordConfirmation = '';
    public string $companyName = '';
    public string $ownerName = '';
    public string $email = '';
    public string $industry = '';

    public $license;
    public bool $agreeTerms = false;
    public bool $agreePrivacy = false;


    /**
     * 본인인증을 마치고 돌아온 첫 화면에서만 인증을 인정한다. 나갔다가 다시 들어오거나 새로고침하면 새로 인증한다.
     * 따로 시간 제한은 두지 않는다 — 화면을 띄워 둔 채면 로그인 세션(SESSION_LIFETIME, 기본 120분)이 끝날 때까지다(2026-09-29).
     */
    public function mount(): void
    {
        Pass::keepOnlyOnReturn('register');
    }

    /** 이 화면의 본인인증 결과. consume 이면 꺼내면서 지운다 */
    private function pass(bool $consume = false): ?array
    {
        return Pass::result('register', $consume, minutes: (int) config('session.lifetime'));
    }

    /** '중복 확인'에서 가입할 수 있다고 확인한 번호(숫자만). 번호를 고치면 비운다 */
    public ?string $checkedBiz = null;

    /**
     * 사업자번호 중복·상태 확인 버튼. 가입 신청 때도 같은 것을 다시 확인한다(그사이 누가 가입했을 수 있다).
     * 아무 번호나 넣어 '누가 회원인지' 알아내는 데 쓰이지 않게, 본인인증을 마친 사람만 10분에 10번까지.
     */
    public function checkBizNumber(): void
    {
        $this->checkedBiz = null;
        $pass = $this->pass();

        if (! $pass) {
            $this->addError('pass', '휴대폰 본인인증을 먼저 해 주세요. 가입 화면을 나갔다 오면 다시 인증해야 합니다.');

            return;
        }

        $key = 'bizcheck:'.$pass['ci_hash'];
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('bizNumber', RateLimiter::availableIn($key).'초 뒤에 다시 확인해 주세요.');

            return;
        }
        RateLimiter::hit($key, 600);

        if ($problem = $this->bizProblem()) {
            $this->addError('bizNumber', $problem);

            return;
        }

        $this->checkedBiz = BizNumber::normalize($this->bizNumber);
    }

    /** 번호를 고치면 확인을 다시 받아야 한다 */
    public function updatedBizNumber(): void
    {
        $this->checkedBiz = null;
        $this->resetErrorBag('bizNumber');
    }

    /** 가입할 수 없는 이유. 가입할 수 있으면 null. 형식 → 중복 → 국세청 상태 순서로 본다 */
    private function bizProblem(): ?string
    {
        if (! BizNumber::isValid($this->bizNumber)) {
            return '사업자등록번호 10자리를 다시 확인해 주세요.';
        }

        if (User::where('biz_no', BizNumber::normalize($this->bizNumber))->exists()) {
            return '이미 가입된 사업자등록번호입니다. 사업자당 계정 하나만 만들 수 있습니다. 로그인하거나 비밀번호 찾기를 이용해 주세요.';
        }

        return match (BiznoStatus::check($this->bizNumber)['result']) {
            'active' => null,
            'closed' => '폐업 사업자로 조회됩니다. 가입할 수 없습니다.',
            'suspended' => '휴업 사업자로 조회됩니다. 가입할 수 없습니다.',
            'not_found' => '국세청에서 조회되지 않는 번호입니다.',
            default => '사업자 상태를 조회하지 못했습니다. 잠시 후 다시 시도해 주세요.',
        };
    }

    public function with(): array
    {
        return [
            'pass' => $this->pass(),
        ];
    }

    public function submit()
    {
        $this->validate([
            'bizNumber' => ['required'],
            'password' => User::passwordRules('passwordConfirmation'),
            'companyName' => ['required', 'string', 'max:100'],
            'ownerName' => ['required', 'string', 'max:50'],
            'email' => User::emailRules(),
            'industry' => ['nullable', 'string', 'max:50'],
            'license' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'agreeTerms' => ['accepted'],
            'agreePrivacy' => ['accepted'],
        ] + $this->regionRules(), User::passwordMessages() + User::emailMessages() + $this->regionMessages() + [
            'license.required' => '사업자등록증을 첨부해 주세요.',
            'agreeTerms.accepted' => '이용약관에 동의해 주세요.',
            'agreePrivacy.accepted' => '개인정보 수집·이용에 동의해 주세요.',
        ]);

        // 화면에 보이는 값이 아니라 세션의 본인인증 결과를 다시 본다
        $pass = $this->pass();

        if (! $pass) {
            $this->addError('pass', '휴대폰 본인인증을 먼저 해 주세요. 가입 화면을 나갔다 오면 다시 인증해야 합니다.');

            return;
        }

        $biz_no = BizNumber::normalize($this->bizNumber);

        if (User::where('biz_no', $biz_no)->exists()) {
            // 사업자당 계정 하나. 중복 안내 화면으로 보낸다.
            return redirect()->route('register.duplicate');
        }

        // '중복 확인' 버튼과 같은 확인을 다시 한다. 버튼을 안 눌렀어도, 그사이 바뀌었어도 여기서 걸린다
        if ($problem = $this->bizProblem()) {
            $this->addError('bizNumber', $problem);

            return;
        }

        // 트랜잭션이 되돌아가면 저장한 등록증 파일도 지워야 한다. 그 경로를 여기 둔다.
        $stored = null;

        try {
            $user = DB::transaction(function () use ($pass, &$stored) {
                $user = new User([
                    'password' => $this->password,
                    'phone' => $pass['phone'],               // 본인인증으로 확인된 번호
                    'phone_verified_at' => now(),
                    'ci_hash' => $pass['ci_hash'],
                    'identity_verified_at' => now(),
                    'email' => $this->email ?: null,
                    'name' => $this->ownerName,
                    'company_name' => $this->companyName,
                    'industry' => $this->industry ?: null,
                    'region_code' => $this->region_code,
                    'region_name' => $this->region_name,
                    'role' => UserRole::Member,
                    'status' => UserStatus::Active,
                    'review_status' => ApplicationStatus::Reviewing,
                    'submitted_at' => now(),
                    'existence_status' => ExistenceStatus::Active,
                    'existence_checked_at' => now(),
                    'submission_revision' => 1,
                    'lock_version' => 0,
                ]);
                $user->biz_no = $this->bizNumber;
                $user->save();

                // 어느 판에 동의했는지 남긴다. 가입과 같은 트랜잭션이라 둘 중 하나만 남는 일이 없다.
                Agreement::recordCurrent($user);

                // 저장 경로가 계정 번호로 정해지므로 계정을 만든 뒤에 옮긴다.
                $stored = $this->license->storeAs('licenses', (string) $user->idx, 'local');

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // 같은 번호로 동시에 신청이 들어온 경우
            $stored && Storage::disk('local')->delete($stored);

            return redirect()->route('register.duplicate');
        } catch (\Throwable $e) {
            $stored && Storage::disk('local')->delete($stored);

            throw $e;
        }

        $this->pass(consume: true);

        // 커밋이 끝난 뒤에 로그인한다.
        auth()->login($user);

        return redirect()->route('register.complete');
    }
}; ?>

<div class="narrow">
    <div class="page-head">
        <div>
            <h1>회원 가입</h1>
            <p class="sub">사업자등록증을 확인한 뒤 승인해 드립니다. 보통 영업일 1일이 걸립니다.</p>
        </div>
    </div>

    @if (session('notice'))
        <p class="note">{{ session('notice') }}</p>
    @endif

    {{-- 1단계: 본인인증. 다날 화면으로 나갔다 오므로 양식보다 먼저 한다(입력한 값이 날아가지 않게). --}}
    @if (! $pass)
        <form class="form-shell" method="post" action="{{ route('pass.start', 'register') }}">
            @csrf
            <div class="form-inner">
            <h2>1. 휴대폰 본인인증</h2>
            <p style="margin-top:12px">가입하려면 먼저 휴대폰 본인인증(PASS)을 해 주세요. 아래 번호가 인증창에 고정되고 계정 휴대폰 번호가 됩니다.</p>
            <div class="field" style="margin-top:16px">
                <label for="pass-phone">휴대폰 번호</label>
                <input id="pass-phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="010-1234-5678" required>
            </div>
            </div>
            <div class="form-footer"><button class="btn primary full" type="submit">휴대폰 본인인증</button></div>
        </form>
    @else
    <form class="form-shell" wire:submit="submit">
        <div class="form-inner">
            @error('pass') <p class="error">{{ $message }}</p> @enderror

            <div class="field">
                <label for="biz">아이디 (사업자등록번호)</label>
                <div class="input-row">
                    <input id="biz" wire:model.live.debounce.500ms="bizNumber" placeholder="123-45-67891" inputmode="numeric">
                    <button type="button" class="btn" style="flex:none" wire:click="checkBizNumber" wire:loading.attr="disabled" wire:target="checkBizNumber">중복 확인</button>
                </div>
                <small>사업자등록번호가 곧 아이디입니다. 사업자당 계정 하나만 만들 수 있습니다.</small>
                @error('bizNumber') <p class="error">{{ $message }}</p> @enderror
                @if ($checkedBiz && $checkedBiz === App\Support\BizNumber::normalize($bizNumber))
                    <p class="note ok">가입할 수 있는 사업자등록번호입니다.</p>
                @endif
            </div>

            <div class="field">
                <label for="pw">비밀번호</label>
                <input id="pw" type="password" wire:model="password" autocomplete="new-password">
                <small>8자 이상 · 대문자·소문자·숫자·특수기호를 각각 하나 이상</small>
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="pw2">비밀번호 확인</label>
                <input id="pw2" type="password" wire:model="passwordConfirmation" autocomplete="new-password">
            </div>

            <div class="field">
                <label for="company">상호 (업체명)</label>
                <input id="company" wire:model="companyName" placeholder="대성포장산업">
                <small>사업자등록증에 적힌 상호와 같아야 합니다.</small>
                @error('companyName') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="industry">업종</label>
                <input id="industry" wire:model="industry" placeholder="포장재 도매" maxlength="50">
                <small>사업자등록증의 업태·종목을 참고해 적어 주세요. 비워 두어도 됩니다.</small>
                @error('industry') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="owner">대표자명</label>
                <input id="owner" wire:model="ownerName" placeholder="홍길동">
                @error('ownerName') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label>휴대폰번호</label>
                <p>{{ preg_replace('/^(\d{3})(\d{3,4})(\d{4})$/', '$1-$2-$3', $pass['phone']) }} <span class="badge">본인인증 완료</span></p>
                <small>본인인증으로 확인한 번호라 여기서 바꿀 수 없습니다. 다른 번호로 가입하려면 처음부터 다시 인증해 주세요.</small>
            </div>

            <div class="field">
                <label>이메일 <span class="dim">(선택)</span></label>
                <x-email-input model="email" />
                <small>인증하지 않는 보조 연락처입니다. 심사 결과는 문자로 보냅니다.</small>
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label>사업장 지역</label>
                <x-address-search :name="$region_name" hint="도로명이나 건물 이름으로 찾아 주세요. 읍·면·동까지만 저장하고 상세 주소는 저장하지 않습니다. 다른 회원에게는 시·군·구까지만 보이고, 재고 등록 때 기본 주소로 쓰입니다." />
                @error('region_code') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label for="license">사업자등록증</label>
                <input id="license" type="file" wire:model="license" accept=".jpg,.jpeg,.png,.pdf">
                <small>JPG · PNG · PDF, 10MB 이하. 심사용이며 다른 회원에게 보이지 않습니다.</small>
                <div wire:loading wire:target="license"><p class="note">파일을 올리는 중입니다…</p></div>
                @error('license') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div class="field">
                <label>약관 동의</label>
                <label class="check-line">
                    <input type="checkbox" wire:model="agreeTerms">
                    <span>이용약관에 동의합니다 (필수) <a href="{{ route('terms') }}" target="_blank">[보기]</a></span>
                </label>
                <label class="check-line">
                    <input type="checkbox" wire:model="agreePrivacy">
                    <span>개인정보 수집·이용에 동의합니다 (필수) <a href="{{ route('privacy') }}" target="_blank">[보기]</a></span>
                </label>
                @error('agreeTerms') <p class="error">{{ $message }}</p> @enderror
                @error('agreePrivacy') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="form-footer">
            <button type="submit" class="btn primary full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">가입 신청</span>
                <span wire:loading wire:target="submit">신청하는 중…</span>
            </button>
        </div>
    </form>
    @endif
</div>
