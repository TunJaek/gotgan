@props(['title' => null, 'nav' => null, 'wide' => false])

@php
    use App\Enums\ApplicationStatus;
    use App\Support\Icon;

    $user = auth()->user();

    // 강조할 메뉴. 화면이 따로 정하지 않으면 지금 주소로 정한다(2026-09-29 — 전에는 늘 '장터'였다).
    $nav ??= match (true) {
        request()->routeIs('market.index') && (int) request('type') === App\Enums\PostType::Want->value => 'wanted',
        request()->routeIs('market.index', 'posts.detail') => 'market',
        request()->routeIs('posts.create', 'posts.edit') => 'register',
        request()->routeIs('inquiries.*') => 'messages',
        request()->routeIs('account.*') => 'account',
        default => '',
    };
    $approved = $user?->review_status === ApplicationStatus::Approved;

    // 나에게 온 문의 중 아직 안 읽은 것을 보낸 '사람 수'. 메시지 수가 아니다.
    // 계산은 User::unreadInquiryCount() 에서 한다(가이드 12장).
    $unread_inquiries = $user?->unreadInquiryCount() ?? 0;
    $items = [
        ['market', '장터', 'box', route('market.index')],
        ['wanted', '구해요', 'search', route('market.index', ['type' => App\Enums\PostType::Want->value])],   // 구해요 목록. 올리기는 그 화면의 버튼
        ['register', '등록', 'plus', route('posts.create')],
        ['messages', '문의', 'chat', route('inquiries.index')],
        ['account', '내 정보', 'user', route('account.index')],
    ];
@endphp
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title ? $title.' · 곳간' : '곳간 — 사업자 재고 직거래' }}</title>
    <x-css file="gotgan.css" />
    <x-css file="app.css" />
    @livewireStyles
</head>
<body>
<div id="root">
    <header class="header">
        <div class="header-inner">
            <a class="brand" href="{{ route('market.index') }}"><img src="{{ asset('images/logo-symbol.png') }}" alt="" width="29" height="32">곳간</a>

            <nav class="desktop-nav" aria-label="주요 메뉴">
                <a href="{{ route('market.index') }}" @class(['active' => $nav === 'market'])>장터</a>
                <a href="{{ route('market.index', ['type' => App\Enums\PostType::Want->value]) }}" @class(['active' => $nav === 'wanted'])>구해요</a>
                <a href="{{ route('inquiries.index') }}" @class(['active' => $nav === 'messages'])>문의</a>
            </nav>

            <div class="header-actions">
                @guest
                    <a class="btn quiet small" href="{{ route('login') }}">로그인</a>
                    <a class="btn primary small" href="{{ route('register') }}">회원 가입</a>
                @else
                    {{-- 말풍선 아이콘 + 안 읽은 문의 사람 수.
                         배지를 아이콘 위에 겹치므로 감싼 <a> 가 기준이 된다(.has-badge 가 position:relative). --}}
                    <a class="icon-btn has-badge" href="{{ route('inquiries.index') }}"
                       aria-label="문의{{ $unread_inquiries ? ' · 새 문의 '.$unread_inquiries.'건' : '' }}">
                        {!! Icon::render('chat') !!}
                        @if ($unread_inquiries)
                            <span class="nav-badge blink" aria-hidden="true">{{ $unread_inquiries > 9 ? '9+' : $unread_inquiries }}</span>
                        @endif
                    </a>
                    <a class="icon-btn" href="{{ route('account.index') }}" aria-label="내 정보">{!! Icon::render('user') !!}</a>
                    <a class="btn primary pc-register" href="{{ route('posts.create') }}">{!! Icon::render('plus') !!}재고 등록</a>
                @endguest
            </div>
        </div>
    </header>

    <main class="wrap" id="main">
        {{ $slot }}
    </main>

    <footer class="footer">
        <span>곳간 · 사업자 재고 직거래</span>
        <span>
            <a href="{{ route('terms') }}">이용약관</a> ·
            <a href="{{ route('privacy') }}"><strong>개인정보처리방침</strong></a>
            @guest · <a href="{{ route('register') }}">회원 가입</a>@endguest
        </span>
    </footer>

    <nav class="mobile-nav" aria-label="주요 메뉴">
        @foreach ($items as [$key, $label, $icon, $url])
            <a href="{{ $url }}" @class(['fab' => $key === 'register', 'active' => $nav === $key])
               @if ($nav === $key) aria-current="page" @endif>
                @if ($key === 'register')
                    <span class="fab-circle">{!! Icon::render('plus') !!}</span>
                @else
                    <span class="has-badge">
                        {!! Icon::render($icon) !!}
                        @if ($key === 'messages' && $unread_inquiries)
                            <span class="nav-badge blink" aria-hidden="true">{{ $unread_inquiries > 9 ? '9+' : $unread_inquiries }}</span>
                        @endif
                    </span>
                @endif
                <span>{{ $label }}</span>
            </a>
        @endforeach
    </nav>
</div>

<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
@livewireScripts
</body>
</html>
