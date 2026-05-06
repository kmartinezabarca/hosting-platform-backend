<?php

namespace App\Providers;

use Spatie\Prometheus\Facades\Prometheus;
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
        if (config('prometheus.enabled')) {
        Prometheus::addGauge('laravel_up')
            ->label('app', config('app.name'))
            ->value(fn() => 1);

        Prometheus::addGauge('laravel_queue_size')
            ->label('app', config('app.name'))
            ->value(fn() => \DB::table('jobs')->count());

        Prometheus::addGauge('laravel_failed_jobs')
            ->label('app', config('app.name'))
            ->value(fn() => \DB::table('failed_jobs')->count());

        Prometheus::addGauge('laravel_users_total')
            ->label('app', config('app.name'))
            ->value(fn() => \DB::table('users')->count());

        Prometheus::addGauge('laravel_services_active')
            ->label('app', config('app.name'))
            ->value(fn() => \DB::table('services')->where('status', 'active')->count());
    }
    }
}
