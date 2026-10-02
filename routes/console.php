<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 보유기간이 지난 기록을 지운다(app/Console/Commands/Purge.php). 서버 cron 에 schedule:run 이 걸려 있어야 돈다.
Schedule::command('app:purge')->dailyAt('04:00');
