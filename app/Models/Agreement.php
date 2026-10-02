<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 이용약관·개인정보처리방침 동의 기록. 문서 본문은 resources/legal/{document}-v{version}.md,
 * 지금 판 번호는 config('gotgan.legal') 에 있다.
 */
class Agreement extends Model
{
    public const DOCUMENTS = ['terms' => '이용약관', 'privacy' => '개인정보처리방침'];

    protected $primaryKey = 'idx';

    protected $fillable = ['user_idx', 'document', 'version', 'agreed_at'];

    protected function casts(): array
    {
        return ['agreed_at' => 'datetime'];
    }

    /** 지금 판의 약관과 처리방침에 동의했다고 남긴다. 가입 트랜잭션 안에서 부른다. */
    public static function recordCurrent(User $user): void
    {
        foreach (array_keys(self::DOCUMENTS) as $document) {
            static::create([
                'user_idx' => $user->idx,
                'document' => $document,
                'version' => config("gotgan.legal.{$document}.version"),
                'agreed_at' => now(),
            ]);
        }
    }

    /** 지금 판 문서의 본문(마크다운). */
    public static function currentText(string $document): string
    {
        $version = config("gotgan.legal.{$document}.version");

        return file_get_contents(resource_path("legal/{$document}-v{$version}.md"));
    }
}
