{{--
 CSS 파일을 불러온다. 주소 뒤에 파일 수정 시각(?v=...)을 붙여서, 파일이 바뀌면 주소도 바뀌게 한다.
 브라우저가 예전 CSS 를 들고 있다가 고친 화면이 안 보이는 일을 막는다(2026-09-28).

   <x-css file="gotgan.css" />
--}}
@props(['file'])
<link rel="stylesheet" href="{{ asset('css/'.$file) }}?v={{ filemtime(public_path('css/'.$file)) }}">
