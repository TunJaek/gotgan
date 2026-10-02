<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 운영 서버에서는 링크·리다이렉트 주소를 https 로 만든다. 로컬(8090)은 그대로 http.
        URL::forceHttps(app()->isProduction());
    }
}
