{{--
 몇 개씩 볼지 고르는 select. 목록 화면의 검색 줄 옆에 둔다.

   <x-admin.per-page />

 값은 App\Concerns\AdminList 의 $per 로 들어가고, 바꾸면 첫 페이지로 돌아간다.
 고를 수 있는 숫자는 거기 updatedPer() 가 한 번 더 거른다 — 둘을 같이 고쳐야 한다.
--}}
<label class="sr" for="per">몇 개씩 보기</label>
<select id="per" wire:model.live="per" title="몇 개씩 보기">
    @foreach ([20, 30, 50, 100] as $n)
        <option value="{{ $n }}">{{ $n }}개씩</option>
    @endforeach
</select>
