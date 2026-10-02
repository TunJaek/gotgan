@props(['title' => null])

{{--
 ┌─ 관리자 레이아웃 ───────────────────────────────────────────────
 │ 관리자 화면 전부가 이것을 쓴다. 각 컴포넌트가
 │   #[Layout('components.admin.layout')]
 │ 로 지정한다. 일반 사용자 layout.blade.php 와 따로 둔 이유는 헤더가 달라야
 │ 하기 때문이다 — 관리자 화면에 '재고 등록' 버튼이 뜨면 안 된다.
 │
 │ 어느 메뉴를 켤지는 라우트 이름에서 알아낸다. 화면마다 넘기지 않는다
 │ (admin.report → reports, admin.post → posts …).
 │
 │ 메뉴의 숫자는 '지금 손봐야 할 건수'다. 0이면 회색, 1 이상이면 빨강.
 │ 화면마다 세지 않고 여기서 한 번만 센다.
 │
 │ 화면 제목은 각 컴포넌트가 .adm-head 로 직접 그린다.
 └────────────────────────────────────────────────────────────────
--}}

@php
    use App\Enums\ApplicationStatus;
    use App\Enums\ExistenceCheckResult;
    use App\Enums\UserStatus;
    use App\Models\ExistenceCheck;
    use App\Models\NotificationLog;
    use App\Models\Post;
    use App\Models\Report;
    use App\Models\User;

    // admin.report / admin.posts 처럼 단수·복수가 섞여 있어 앞쪽 조각으로 맞춘다.
    $route = request()->route()?->getName() ?? '';
    $nav = match (true) {
        str_starts_with($route, 'admin.application'), str_starts_with($route, 'admin.review') => 'applications',
        str_starts_with($route, 'admin.report') => 'reports',
        str_starts_with($route, 'admin.post')   => 'posts',
        str_starts_with($route, 'admin.user')   => 'users',
        str_starts_with($route, 'admin.existence') => 'existence-checks',
        str_starts_with($route, 'admin.notification') => 'notification-logs',
        default => 'index',
    };

    $menu = [
        ['index',        '홈',       route('admin.index'),        null],
        ['applications', '가입 심사', route('admin.applications'), User::where('review_status', ApplicationStatus::Reviewing)->whereNotNull('submitted_at')->count()],
        ['reports',      '신고',     route('admin.reports'),      Report::unresolved()->count()],
        ['posts',        '매물 관리', route('admin.posts'),        Post::where('is_hidden', true)->count()],
        ['users',        '회원 관리', route('admin.users'),        User::where('status', UserStatus::Suspended)->count()],
        ['existence-checks', '존속 점검', route('admin.existence-checks'), ExistenceCheck::where('result', '!=', ExistenceCheckResult::Active)->count()],
        ['notification-logs', '문자 이력', route('admin.notification-logs'), NotificationLog::where('success', false)->count()],
    ];
@endphp

<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title ? $title.' · 곳간 관리자' : '곳간 관리자' }}</title>
    <x-css file="gotgan.css" />
    <x-css file="app.css" />
    <x-css file="admin.css" />
    @livewireStyles
</head>
<body>
<header class="adm-top">
    <div class="adm-top-inner">
        <a class="adm-brand" href="{{ route('admin.index') }}"><img src="{{ asset('images/logo-symbol.png') }}" alt="" width="20" height="22">곳간 관리자</a>
        <div class="adm-right">
            <span class="adm-who">{{ auth()->user()?->company_name }}</span>
            <a href="{{ route('market.index') }}">사이트 보기</a>
            {{-- 로그아웃은 POST 다. 링크로 걸면 남이 주소만 물려도 로그아웃된다. --}}
            <form method="post" action="{{ route('logout') }}" style="display:inline">
                @csrf
                <button type="submit" style="border:0;background:none;color:#A9BDC8;font:inherit;font-size:13px;padding:0">로그아웃</button>
            </form>
        </div>
    </div>
</header>

<nav class="adm-menu" aria-label="관리자 메뉴">
    <div class="adm-menu-inner">
        @foreach ($menu as [$key, $label, $url, $count])
            <a href="{{ $url }}" @class(['active' => $nav === $key])
               @if ($nav === $key) aria-current="page" @endif>
                {{ $label }}
                @if ($count !== null)
                    <span @class(['cnt', 'on' => $count > 0])>{{ $count }}</span>
                @endif
            </a>
        @endforeach
    </div>
</nav>

<main class="adm-wrap">
    {{ $slot }}
</main>

@livewireScripts
</body>
</html>
