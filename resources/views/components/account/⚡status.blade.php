<?php

use App\Enums\ApplicationStatus;
use App\Enums\UserStatus;
use App\Enums\ReviewDecision;
use Livewire\Component;

new class extends Component
{
    public function with(): array
    {
        return [
            'user' => auth()->user()?->load('reviews'),
        ];
    }
}; ?>

<div class="narrow">
    <div class="page-head">
        <div>
            <h1>내 정보</h1>
            <p class="sub">{{ $user?->company_name }} · {{ $user?->bizNo() }}</p>
        </div>

        {{-- 로그아웃은 링크가 아니라 폼이다. 주소만으로 남을 로그아웃시킬 수 없어야 한다(CSRF). --}}
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn" type="submit">로그아웃</button>
        </form>
    </div>

    {{-- 다른 화면에서 돌려보내며 남긴 안내(자료 보완 제출, 연락처 열람 제한 등) --}}
    @if (session('notice'))
        <p class="note">{{ session('notice') }}</p>
    @endif

    {{-- 이용정지된 계정에만 나온다. 사유는 운영자가 적는다. --}}
    @if ($user->status === UserStatus::Suspended)
        <section class="panel" style="border-color:#E5BBB5">
            <div class="chips">
                <span class="badge red">이용정지</span>
            </div>
            <p style="margin-top:14px">{{ $user->suspended_reason ?: '사유가 기록되지 않았습니다.' }}</p>
            <p class="sub" style="margin-top:12px">문의가 필요하시면 고객센터로 연락해 주세요.</p>
        </section>
    @endif

    <section class="panel" style="margin-top:14px">
        <h2>계정 정보</h2>
        <dl class="facts" style="margin-top:16px">
            <dt>상호</dt>
            <dd>{{ $user->company_name ?? '-' }}</dd>

            <dt>사업자등록번호</dt>
            <dd>{{ $user->bizNo() }}</dd>

            <dt>대표자명</dt>
            <dd>{{ $user->name ?? '-' }}</dd>

            <dt>휴대폰</dt>
            <dd>{{ $user->formattedPhone() }}</dd>

            <dt>이메일</dt>
            <dd>{{ $user->email ?: '등록 안 함' }}</dd>

            <dt>업종</dt>
            <dd>{{ $user->industry ?: '-' }}</dd>

            <dt>지역</dt>
            <dd>{{ $user->regionName(full: true) }}</dd>

            <dt>가입일</dt>
            <dd>{{ $user->created_at?->timezone('Asia/Seoul')->format('Y-m-d') }}</dd>
        </dl>

        <a class="btn" href="{{ route('account.edit') }}" style="margin-top:16px">정보 수정</a>
    </section>

    @if ($user->isApproved())
        <div class="account-links">
            <a href="{{ route('account.posts') }}">내 매물 {!! App\Support\Icon::render('box') !!}</a>
            <a href="{{ route('account.posts', ['tab' => 'favorites']) }}">관심 매물 {!! App\Support\Icon::render('heart') !!}</a>
        </div>
    @endif

    {{-- 가입 심사 현황·이력은 승인 전(심사중·보완·반려)에만 보여 준다. 승인된 뒤에는 볼 일이 없다 --}}
    @if ($user->review_status !== ApplicationStatus::Approved)
    <h2 style="margin:24px 0 14px">가입 심사 현황</h2>

    @php
        $status = $user->review_status;
        $badge = match ($status) {
            ApplicationStatus::Supplement => ['자료 보완 필요', 'yellow'],
            ApplicationStatus::Rejected => ['반려', 'red'],
            default => ['심사중', 'yellow'],
        };
    @endphp

    <section class="panel">
        <div class="chips">
            <span class="badge {{ $badge[1] }}">{{ $badge[0] }}</span>
            @if ($user->submitted_at)
                <span class="dim">신청일 {{ $user->submitted_at->timezone('Asia/Seoul')->format('Y년 n월 j일') }}</span>
            @endif
        </div>

        @if ($status === ApplicationStatus::Reviewing)
            <p style="margin-top:16px">관리자가 사업자등록증을 확인하고 있습니다. 결과는 가입할 때 인증한 번호로 문자를 보내드립니다.</p>
            <p class="sub">심사 중에도 장터를 둘러볼 수 있습니다. 문의·등록은 승인 후에 가능합니다.</p>
        @elseif ($status === ApplicationStatus::Supplement)
            <p style="margin-top:16px">보완이 필요합니다. 아래 사유를 확인하고 자료를 다시 올려 주세요.</p>
            <p class="note">{{ $user->reviews->first()?->reason }}</p>
            <a class="btn primary" href="{{ route('account.supplement') }}">자료 다시 올리기</a>
        @elseif ($status === ApplicationStatus::Rejected)
            <p style="margin-top:16px">가입이 반려되었습니다.</p>
            <p class="note">{{ $user->reviews->first()?->reason }}</p>
        @endif
    </section>

    <section class="panel" style="margin-top:14px">
        <h2>심사 이력</h2>
        @forelse ($user->reviews as $review)
            <div class="listing-meta" style="padding:10px 0;border-bottom:1px solid var(--line)">
                <span class="badge {{ $review->decision === ReviewDecision::Rejected ? 'red' : ($review->decision === ReviewDecision::Approved ? '' : 'yellow') }}">
                    {{ $review->decision->label() }}
                </span>
                <span>{{ $review->reviewed_at?->timezone('Asia/Seoul')->format('Y-m-d H:i') }}</span>
                <span>{{ $review->reason }}</span>
            </div>
        @empty
            <p class="sub">아직 심사 기록이 없습니다.</p>
        @endforelse
    </section>
    @endif

    {{-- 탈퇴는 찾을 수 있어야 한다(개인정보 권리). 관리자는 탈퇴할 수 없어서 보이지 않는다 --}}
    @unless ($user->isAdmin())
        <section class="panel" style="margin-top:14px">
            <h2>회원 탈퇴</h2>
            <p class="sub" style="margin-top:8px">탈퇴하면 회원 정보와 사업자등록증이 지워지고, 올린 매물은 모두 숨겨집니다. 되돌릴 수 없습니다.</p>
            <a class="btn danger" href="{{ route('account.withdraw') }}" style="margin-top:14px">탈퇴하기</a>
        </section>
    @endunless

    {{-- 휴대폰 장터는 자동 불러오기라 푸터에 닿기 어렵다. 하단 메뉴 '내 정보'에서도 약관에 닿게 둔다(2026-09-30) --}}
    <p class="legal">
        <a href="{{ route('terms') }}">이용약관</a> · <a href="{{ route('privacy') }}"><strong>개인정보처리방침</strong></a>
    </p>
</div>
