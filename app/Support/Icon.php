<?php

namespace App\Support;

/**
 * 시안(곳간_UI_수정안_v0.3)에서 가져온 아이콘 세트.
 * 이름으로 꺼내 쓰고, 없는 이름은 box 로 대체한다.
 */
class Icon
{
    private static ?array $icons = null;

    public static function render(string $name, string $class = ''): string
    {
        self::$icons ??= json_decode(file_get_contents(resource_path('icons.json')), true);

        $path = self::$icons[$name] ?? self::$icons['box'];
        $attr = $class !== '' ? ' class="'.e($class).'"' : '';

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
            .'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'.$attr.'>'.$path.'</svg>';
    }
}
