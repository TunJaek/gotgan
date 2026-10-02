<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 카테고리를 사무기기(프린터) 업종 기준으로 바꾼다(2026-10-02).
 *
 *   옛 대분류 1~6(포장재·원자재·부품·기계·공구·건축 자재·사무 비품)은 끈다. 행은 남긴다 — posts.category_id 가 FK 라서.
 *   기타(9)는 그대로 쓰고, 옛 카테고리에 있던 글은 전부 기타로 옮긴다.
 *   새 대분류는 10~14, 중분류는 15 부터.
 */
return new class extends Migration
{
    private const OLD_IDX = [1, 2, 3, 4, 5, 6, 8];

    /** [대분류 idx, 이름, [중분류 이름 …]] */
    private const TREE = [
        [10, '복합기·프린터', ['레이저 복합기(흑백)', '레이저 복합기(컬러)', '프린터(단일 기능)', '잉크젯·무한잉크', '대형 플로터·생산형']],
        [11, '소모품', ['토너(정품)', '토너(재생·호환)', '잉크', '드럼·현상기', '용지']],
        [12, '부품·옵션', ['정착기(퓨저)', '롤러·급지 부품', '메인보드·전원부', '피니셔·급지대·카세트']],
        [13, '기타 사무기기', ['팩스·스캐너', '문서세단기', '제본기·코팅기', '라벨·영수증 프린터']],
        [14, '수리·충전 장비', ['토너 충전 장비', '수리 공구·측정기']],
    ];

    public function up(): void
    {
        $now = now();
        $rows = [];
        $child_idx = 15;

        foreach (self::TREE as $order => [$idx, $name, $children]) {
            $rows[] = ['idx' => $idx, 'parent_idx' => null, 'name' => $name, 'sort_order' => $order + 1];
            foreach ($children as $child_order => $child_name) {
                $rows[] = ['idx' => $child_idx++, 'parent_idx' => $idx, 'name' => $child_name, 'sort_order' => $child_order + 1];
            }
        }

        DB::table('categories')->insert(array_map(fn ($row) => $row + [
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $rows));

        DB::table('categories')->whereIn('idx', self::OLD_IDX)->update(['is_active' => false, 'updated_at' => $now]);
        DB::table('posts')->whereIn('category_id', self::OLD_IDX)->update(['category_id' => 9]);
    }

    public function down(): void
    {
        // 글이 원래 어느 카테고리였는지는 남아 있지 않다. 새 카테고리 글은 기타로 보내고 옛 카테고리만 다시 켠다.
        $new_idx = DB::table('categories')->where('idx', '>=', 10)->pluck('idx');

        DB::table('posts')->whereIn('category_id', $new_idx)->update(['category_id' => 9]);
        DB::table('categories')->whereNotNull('parent_idx')->whereIn('idx', $new_idx)->delete();
        DB::table('categories')->whereIn('idx', $new_idx)->delete();
        DB::table('categories')->whereIn('idx', [1, 2, 3, 4, 5, 6])->update(['is_active' => true]);
    }
};
