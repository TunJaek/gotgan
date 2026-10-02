<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 기본 시드는 샘플 매물이다. 계정도 여기서 같이 만든다.
        $this->call(SamplePostSeeder::class);
    }
}
