{{-- Livewire 페이지 컴포넌트는 이 레이아웃으로 감싸진다. 실제 껍데기는 x-layout 하나만 쓴다. --}}
<x-layout :title="$title ?? null" :nav="$nav ?? null">
    {{ $slot }}
</x-layout>
