{{-- 다날 인증창(또는 개발용 가짜 인증)으로 자동으로 넘어간다. routes/web.php 의 /pass/start 가 쓴다. --}}
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>본인인증으로 이동 · 곳간</title>
</head>
<body>
    <form id="pass" action="{{ $action }}" method="post">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        {{-- 개발용 가짜 인증은 곳간 안이라 CSRF 토큰이 필요하다. 다날로 갈 때는 넣지 않는다. --}}
        @if ($fake)
            @csrf
        @endif
        <noscript><button type="submit">본인인증으로 이동</button></noscript>
    </form>
    <script>document.getElementById('pass').submit();</script>
</body>
</html>
