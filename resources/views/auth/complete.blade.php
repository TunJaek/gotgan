<x-layout title="가입 신청 접수">
    <div class="narrow">
        <section class="panel" style="text-align:center;padding:48px 24px">
            <h1>가입 신청을 접수했습니다</h1>
            <p class="sub" style="margin-top:12px">
                관리자가 사업자등록증을 확인한 뒤 결과를 문자로 보내드립니다. 보통 영업일 1일이 걸립니다.
            </p>
            <div class="actions" style="margin-top:24px">
                <a class="btn primary" href="{{ route('account.index') }}">신청 현황 보기</a>
                <a class="btn" href="{{ route('market.index') }}">장터 둘러보기</a>
            </div>
        </section>
    </div>
</x-layout>
