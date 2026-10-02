<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\BizNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 로그인 계정이자 사업체다. 계정과 사업체가 1:1 이라 테이블을 나누지 않는다.
 *
 * name         대표자명
 * company_name 상호
 * status       계정 상태(정상·이용정지)
 * review_status 가입 심사 상태
 */
class User extends Authenticatable
{
    protected $primaryKey = 'idx';

    protected $fillable = [
        'password', 'phone', 'phone_verified_at', 'ci_hash', 'identity_verified_at', 'email', 'name',
        'company_name', 'biz_no', 'industry', 'region_code', 'region_name',
        'role', 'status', 'review_status', 'submitted_at', 'approved_at',
        'existence_status', 'existence_checked_at', 'submission_revision', 'lock_version',
    ];

    protected $hidden = ['password', 'remember_token', 'ci_hash'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'existence_checked_at' => 'datetime',
            'password' => 'hashed',
            'withdrawn_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'review_status' => ApplicationStatus::class,
            'existence_status' => ExistenceStatus::class,
        ];
    }

    /** 저장할 때는 항상 하이픈을 떼고 넣는다. */
    public function setBizNoAttribute(string $value): void
    {
        $this->attributes['biz_no'] = BizNumber::normalize($value);
    }

    /** 화면에 쓸 123-45-67890 형태. */
    public function bizNo(): string
    {
        return BizNumber::format($this->biz_no ?? '');
    }

    /** 010-1234-5678 형태. */
    public function formattedPhone(): string
    {
        $phone = preg_replace('/\D/', '', $this->phone ?? '');

        return strlen($phone) === 11
            ? substr($phone, 0, 3).'-'.substr($phone, 3, 4).'-'.substr($phone, 7)
            : $phone;
    }

    /**
     * 사업장 지역 이름. 다른 회원·목록에는 시군구까지('서울 동작구'), $full 이면 동까지('서울 동작구 상도동').
     * 동까지는 본인 내 정보와 가입 심사(사업자등록증 주소와 맞춰 보기)에서만 보인다. 고르지 않았으면 '미설정'.
     */
    public function regionName(bool $full = false): string
    {
        if (! $this->region_name) {
            return '미설정';
        }

        // 예전 가입자는 시군구(5자리)까지만 있다
        return $full || strlen((string) $this->region_code) < 8
            ? $this->region_name
            : \Illuminate\Support\Str::beforeLast($this->region_name, ' ');
    }

    /**
     * 탈퇴. 행은 남기고 개인정보를 지운다. 되돌릴 수 없다.
     * 상대방의 대화·신고·심사 이력이 이 행을 가리키므로 행 자체를 지우지 않는다.
     * 대화 상대 화면에는 상호 대신 '탈퇴한 회원'이 보인다.
     */
    public function withdraw(string $action = 'user.withdrawn', ?string $reason = null): void
    {
        DB::transaction(function () use ($action, $reason) {
            foreach (Post::where('user_idx', $this->idx)->get() as $post) {
                // 연락처 공개를 끄고 동의에 철회 시각을 찍는다
                $changes = ['show_phone' => false];

                // 정지 복구가 되살리지 않도록 숨김 이유를 따로 적는다(Moderation::reinstateUser 참고)
                if (! $post->is_hidden) {
                    $changes['is_hidden'] = true;
                    AuditLog::record('post.hidden.by_withdraw', 'post', $post->idx);
                }

                $post->update($changes);
                ContactConsent::syncFor($post);
            }

            DB::table('favorites')->where('user_idx', $this->idx)->delete();
            DB::table('sessions')->where('user_id', $this->idx)->delete();

            $this->forceFill([
                'name' => '탈퇴회원',
                'company_name' => '탈퇴한 회원',
                'phone' => '',
                'phone_verified_at' => null,
                'ci_hash' => null,
                'identity_verified_at' => null,
                'email' => null,
                'industry' => null,
                'region_code' => null,
                'region_name' => null,
                'suspended_reason' => null,
                'password' => Str::random(40),   // 아무도 모르는 값. 로그인할 수 없다
                'remember_token' => null,
                'status' => UserStatus::Withdrawn,
                'withdrawn_at' => now(),
            ]);

            // 같은 사업자번호로 다시 가입할 수 있게 번호를 비운다. 숫자가 아니라 로그인 입력과 겹칠 일이 없다.
            // setBizNoAttribute 가 숫자만 남기므로 직접 넣는다.
            $this->attributes['biz_no'] = 'W'.str_pad((string) $this->idx, 9, '0', STR_PAD_LEFT);
            $this->save();

            // 회원이 스스로 탈퇴하면 user.withdrawn, 반려 신청을 보유기간 뒤 정리하면 user.purged(app:purge),
            // 운영자가 요청을 받아 처리하면 user.withdrawn.by_admin(사유 필수)
            AuditLog::record($action, 'user', $this->idx, $reason);
        });

        // 파일은 DB 가 끝난 뒤에 지운다. DB 가 되돌아갔는데 파일만 사라지면 안 된다.
        Storage::disk('local')->delete($this->licenseKey());
    }

    /**
     * 비밀번호 규칙. 가입·비밀번호 찾기가 같이 쓴다. 8~64자, 대문자·소문자·숫자·특수기호 각 하나 이상.
     * 64자 상한은 bcrypt 가 72바이트까지만 반영하기 때문이다.
     *
     * @param  string  $confirm_field  비밀번호 확인 입력칸의 속성 이름
     */
    public static function passwordRules(string $confirm_field): array
    {
        return ['required', 'string', 'min:8', 'max:64',
            'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/\d/', 'regex:/[^A-Za-z0-9]/',
            'same:'.$confirm_field];
    }

    /**
     * 이메일(선택) 규칙. 가입·자료 보완·정보 수정이 같이 쓴다.
     * 기본 email 규칙은 'abc@naver' 처럼 점 없는 도메인도 통과시켜서 strict 와 끝자리 2글자 이상을 더한다(2026-09-29).
     * 도메인이 실제로 있는지(dns)는 보지 않는다 — 외부 조회라 느리고 네트워크에 따라 멀쩡한 주소가 막힌다.
     */
    public static function emailRules(): array
    {
        return ['nullable', 'email:rfc,strict', 'regex:/\.[^.@]{2,}$/', 'max:255'];
    }

    public static function emailMessages(): array
    {
        $message = '이메일 주소를 다시 확인해 주세요. (예: owner@company.co.kr)';

        return ['email.email' => $message, 'email.regex' => $message];
    }

    /** passwordRules() 의 안내 문구. validate() 의 두 번째 인자에 합친다. */
    public static function passwordMessages(): array
    {
        return [
            'password.regex' => '비밀번호는 대문자·소문자·숫자·특수기호를 각각 하나 이상 포함해야 합니다.',
            'password.min' => '비밀번호는 8자 이상이어야 합니다.',
            'password.max' => '비밀번호는 64자 이하여야 합니다.',
            'password.same' => '비밀번호가 서로 다릅니다.',
        ];
    }

    /**
     * 사업자등록증 저장 경로. 확장자를 붙이지 않아 계정 번호만으로 정해진다.
     * 종류는 내보낼 때 파일에서 알아낸다.
     */
    public function licenseKey(): string
    {
        return 'licenses/'.$this->idx;
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function favoritePosts()
    {
        return $this->belongsToMany(Post::class, 'favorites', 'user_idx', 'post_idx')
            ->withTimestamps(updatedAt: false);   // 관심 표시는 켜고 끄는 것뿐이라 수정 시각이 없다
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ApplicationReview::class, 'user_idx')->latest('reviewed_at');
    }

    /** 내가 낀 대화방. */
    public function inquiries(): Builder
    {
        return Inquiry::query()->forUser($this->idx);
    }

    /**
     * 나에게 온 문의 중 아직 안 읽은 것을 보낸 '사람 수'. 헤더 말풍선 배지가 쓴다.
     * 메시지 수가 아니라 방 수다 — 한 사람이 다섯 번 보내도 1이다.
     */
    public function unreadInquiryCount(): int
    {
        return $this->unreadRooms()->where('to_user_idx', $this->idx)->count();
    }

    /** 내가 건 문의에 답장한 '사람 수'. 문의함의 보낸 문의 탭 알림이 쓴다. */
    public function unreadReplyCount(): int
    {
        return $this->unreadRooms()->where('from_user_idx', $this->idx)->count();
    }

    /**
     * 안 읽은 메시지가 하나라도 있는 내 대화방.
     *
     * 받은 문의냐 보낸 문의냐는 '누가 방을 먼저 열었나'로 가른다.
     * 내가 from 이면 내가 말을 건 방(보낸 문의), to 면 상대가 건 방(받은 문의)이다.
     */
    private function unreadRooms(): Builder
    {
        return Inquiry::query()
            ->forUser($this->idx)
            ->whereHas('messages', function ($q) {
                $q->where('sender_idx', '!=', $this->idx)->whereNull('read_at');
            });
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * 승인 회원만 문의·등록·연락처 확인·관심 표시를 할 수 있다.
     * 관리자도 같은 기준이다(관리자 계정은 승인·정상 상태로 만들어진다). 9-29 에 관리자를 뺐다가 9-30 에 되돌렸다.
     */
    public function isApproved(): bool
    {
        return $this->status === UserStatus::Active
            && $this->review_status === ApplicationStatus::Approved
            && $this->existence_status === ExistenceStatus::Active;
    }
}
