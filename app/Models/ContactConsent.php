<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 매물 연락처 공개 동의. 판매자 휴대폰 번호를 다른 회원에게 보여 주려면 매물마다 동의가 있어야 한다.
 * 동의 문구는 posts/⚡create.blade.php 에 있다. 문구를 고치면 VERSION 을 올린다.
 */
class ContactConsent extends Model
{
    public const VERSION = 1;

    protected $primaryKey = 'idx';

    protected $fillable = ['post_idx', 'user_idx', 'version', 'agreed_at', 'withdrawn_at'];

    protected function casts(): array
    {
        return [
            'agreed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * 매물을 저장한 직후에 부른다. posts.show_phone 과 동의 기록을 맞춘다.
     * 공개인데 열린 동의가 없으면 새로 남기고, 비공개인데 열린 동의가 있으면 철회 시각을 찍는다.
     */
    public static function syncFor(Post $post): void
    {
        $open = static::where('post_idx', $post->idx)->whereNull('withdrawn_at')->first();

        if ($post->show_phone && ! $open) {
            static::create([
                'post_idx' => $post->idx,
                'user_idx' => $post->user_idx,
                'version' => self::VERSION,
                'agreed_at' => now(),
            ]);
        } elseif (! $post->show_phone && $open) {
            $open->update(['withdrawn_at' => now()]);
        }
    }
}
