<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;

/**
 * 관리자 목록 화면이 공유하는 것 — 표의 조작 방식만.
 *
 * 무엇을 조회할지, 어떤 열을 보여줄지, 무슨 업무 처리를 할지는 각 화면에 둔다.
 * 여기 있는 것은 "정렬하면 이렇게 돈다", "검색을 바꾸면 첫 페이지로 간다" 같은 규칙뿐이다.
 *
 * 쓰는 쪽에서 할 일
 *   1. use AdminList;
 *   2. sortable() 로 정렬 허용 열을 알려준다 (여기 없는 열 이름이 오면 무시한다)
 *   3. 조회할 때 applySort($query) 를 부른다
 */
trait AdminList
{
    use WithPagination;

    public string $sort = '';

    public string $dir = 'desc';

    public string $keyword = '';

    public int $per = 30;

    /**
     * 개수를 바꾸면 첫 페이지로. 5페이지를 보다가 100개씩으로 바꾸면 빈 화면이 나온다.
     *
     * 고를 수 있는 숫자는 components/admin/per-page.blade.php 의 목록과 같아야 한다.
     * 화면에 없는 값도 요청으로 보낼 수 있으므로 여기서 거른다 — 안 거르면
     * per=999999 로 한 번에 다 끌어올 수 있다.
     */
    public function updatedPer(): void
    {
        if (! in_array($this->per, [20, 30, 50, 100], true)) {
            $this->per = 30;
        }

        $this->resetPage();
    }

    /** 정렬을 허용할 열. 화면마다 덮어쓴다. */
    protected function sortable(): array
    {
        return [];
    }

    /** 정렬 기본값. 화면마다 덮어쓴다. */
    protected function defaultSort(): array
    {
        return ['idx', 'desc'];
    }

    /**
     * 머리글을 눌렀을 때. 같은 열을 다시 누르면 방향만 뒤집는다.
     *
     * 화면에 없는 열 이름도 요청으로 보낼 수 있으므로 여기서 거른다.
     * 거르지 않으면 정렬 이름이 그대로 SQL 로 들어간다.
     */
    public function sortBy(string $column): void
    {
        if (! in_array($column, $this->sortable(), true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->dir = 'asc';
        }

        $this->resetPage();
    }

    /** 검색어를 고치면 첫 페이지로. 3페이지를 보다가 검색하면 빈 화면이 나온다. */
    public function updatedKeyword(): void
    {
        $this->resetPage();
    }

    /** 탭을 바꿔도 마찬가지다. 화면에 $tab 이 있으면 Livewire 가 이걸 부른다. */
    public function updatedTab(): void
    {
        $this->resetPage();
    }

    /**
     * 정렬을 쿼리에 붙인다.
     *
     * 값이 같은 행이 여럿이면 페이지를 넘길 때마다 순서가 흔들린다.
     * 그래서 마지막에 idx 를 한 번 더 건다.
     */
    protected function applySort(Builder $query): Builder
    {
        [$col, $dir] = $this->currentSort();

        $query->orderBy($col, $dir);

        if ($col !== 'idx') {
            $query->orderBy('idx', 'desc');
        }

        return $query;
    }

    /** 지금 정렬 상태. 화면이 머리글 화살표를 그릴 때도 쓴다. */
    public function currentSort(): array
    {
        [$def_col, $def_dir] = $this->defaultSort();

        $col = in_array($this->sort, $this->sortable(), true) ? $this->sort : $def_col;
        $dir = $this->dir === 'asc' ? 'asc' : 'desc';

        if ($col === $def_col && $this->sort === '') {
            $dir = $def_dir;
        }

        return [$col, $dir];
    }

    /** 다듬은 검색어. 공백만 넣은 경우를 걸러준다. */
    protected function term(): string
    {
        return trim($this->keyword);
    }
}
