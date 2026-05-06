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
    Prometheus::addGauge('Laravel Up', fn() => 1, 'laravel_up');

    Prometheus::addGauge('Queue Jobs', fn() => \DB::table('jobs')->count(), 'laravel_queue_jobs');

    Prometheus::addGauge('Failed Jobs', fn() => \DB::table('failed_jobs')->count(), 'laravel_failed_jobs');

    Prometheus::addGauge('Users Total', fn() => \DB::table('users')->count(), 'laravel_users_total');

    Prometheus::addGauge('Services Active', fn() => \DB::table('services')->where('status', 'active')->count(), 'laravel_services_active');
}
    }
}
