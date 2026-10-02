@props(['rows'])

{{--
 페이지 링크. Livewire 가 주는 paginator 를 그대로 받는다.

   <x-admin.pager :rows="$reports" />

 기본 뷰를 쓰지 않는 이유는 Bootstrap 마크업이 나오기 때문이다.
 여기서는 자체 CSS(.adm-pager)에 맞춰 직접 그린다.
--}}

@if ($rows->hasPages() || $rows->total() > 0)
    <div class="adm-pager">

        @if ($rows->hasPages())
            @if ($rows->onFirstPage())
                <span class="disabled">이전</span>
            @else
                <button type="button" wire:click="previousPage" class="adm-btn tiny">이전</button>
            @endif

            @foreach ($rows->getUrlRange(max(1, $rows->currentPage() - 2), min($rows->lastPage(), $rows->currentPage() + 2)) as $page => $url)
                @if ($page === $rows->currentPage())
                    <span class="on">{{ $page }}</span>
                @else
                    <button type="button" wire:click="gotoPage({{ $page }})" class="adm-btn tiny">{{ $page }}</button>
                @endif
            @endforeach

            @if ($rows->hasMorePages())
                <button type="button" wire:click="nextPage" class="adm-btn tiny">다음</button>
            @else
                <span class="disabled">다음</span>
            @endif
        @endif
    </div>
@endif
