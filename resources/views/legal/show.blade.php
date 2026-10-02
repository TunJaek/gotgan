{{-- 이용약관·개인정보처리방침. 본문은 resources/legal/*.md 를 그대로 보여 준다(routes/web.php 의 /terms, /privacy). --}}
<x-layout :title="$title">
    <div class="narrow legal-doc">
        {!! $html !!}
    </div>
</x-layout>
