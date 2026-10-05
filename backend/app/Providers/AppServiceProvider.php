<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            \App\Services\Contracts\MediaStorageInterface::class,
            \App\Services\MediaStorageService::class
        );
        $this->app->singleton(
            \App\Services\Contracts\FFmpegServiceInterface::class,
            \App\Services\FFmpegService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
