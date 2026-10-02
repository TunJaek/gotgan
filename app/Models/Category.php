<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 매물 카테고리. 대·중·소분류를 parent_idx 하나로 표현한다. 최대 3단계.
 * 카테고리를 더할 때는 새 마이그레이션에서 insert 한다(관리 화면 없음, 2026-09-28).
 */
class Category extends Model
{
    public const MAX_DEPTH = 3;

    protected $primaryKey = 'idx';

    protected $fillable = ['parent_idx', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_idx');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_idx');
    }

    /** '원자재 › 금속 › 철강' 처럼 위에서부터 이어 붙인 이름. */
    public function pathName(): string
    {
        $names = [$this->name];

        for ($c = $this->parent; $c; $c = $c->parent) {
            array_unshift($names, $c->name);
        }

        return implode(' › ', $names);
    }

    /**
     * 목록 필터용. 켜진 카테고리 전부를 나무 순서대로. [idx => ['name' => 이름, 'depth' => 1~3]]
     *
     * @return array<int, array{name: string, depth: int}>
     */
    public static function menu(): array
    {
        $children = static::where('is_active', true)
            ->orderBy('sort_order')->orderBy('idx')
            ->get()
            ->groupBy(fn ($c) => $c->parent_idx ?? 0);

        $result = [];

        $walk = function (int $parent_idx, int $depth) use (&$walk, &$result, $children) {
            foreach ($children[$parent_idx] ?? [] as $category) {
                $result[$category->idx] = ['name' => $category->name, 'depth' => $depth];
                if ($depth < self::MAX_DEPTH) {
                    $walk($category->idx, $depth + 1);
                }
            }
        };
        $walk(0, 1);

        return $result;
    }

    /** 이 카테고리와 그 아래(최대 3단계) 카테고리 번호. 목록에서 대분류를 고르면 하위 분류 매물까지 나온다. */
    public static function withDescendants(int $idx): array
    {
        $ids = [$idx];
        $level = [$idx];

        for ($depth = 1; $depth < self::MAX_DEPTH && $level; $depth++) {
            $level = static::whereIn('parent_idx', $level)->pluck('idx')->all();
            $ids = array_merge($ids, $level);
        }

        return $ids;
    }

    /**
     * 새 글에서 고를 수 있는 카테고리. [idx => 경로 이름], 화면에 보일 순서대로.
     * 켜져 있고, 켜진 하위 분류가 없는 가장 끝 단계만 나온다. 등록 화면의 선택지와 검증이 같이 쓴다.
     *
     * @return array<int, string>
     */
    public static function selectable(): array
    {
        // 수십 개 수준이라 통째로 읽어 PHP 에서 나무를 탄다.
        $children = static::where('is_active', true)
            ->orderBy('sort_order')->orderBy('idx')
            ->get()
            ->groupBy(fn ($c) => $c->parent_idx ?? 0);

        $result = [];

        $walk = function (int $parent_idx, string $prefix, int $depth) use (&$walk, &$result, $children) {
            foreach ($children[$parent_idx] ?? [] as $category) {
                $path = $prefix.$category->name;

                if ($depth < self::MAX_DEPTH && isset($children[$category->idx])) {
                    $walk($category->idx, $path.' › ', $depth + 1);
                } else {
                    $result[$category->idx] = $path;
                }
            }
        };
        $walk(0, '', 1);

        return $result;
    }
}
