<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\Prometheus\Collectors\Horizon\CurrentMasterSupervisorCollector;
use Spatie\Prometheus\Collectors\Horizon\CurrentProcessesPerQueueCollector;
use Spatie\Prometheus\Collectors\Horizon\CurrentWorkloadCollector;
use Spatie\Prometheus\Collectors\Horizon\FailedJobsPerHourCollector;
use Spatie\Prometheus\Collectors\Horizon\HorizonStatusCollector;
use Spatie\Prometheus\Collectors\Horizon\JobsPerMinuteCollector;
use Spatie\Prometheus\Collectors\Horizon\RecentJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueDelayedJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueOldestPendingJobCollector;
use Spatie\Prometheus\Collectors\Queue\QueuePendingJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueReservedJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueSizeCollector;
use Spatie\Prometheus\Facades\Prometheus;

class PrometheusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Servicios ─────────────────────────────────────────
        Prometheus::addGauge(
            'Services Active',
            fn() => DB::table('services')->where('status', 'active')->count()
        );

        Prometheus::addGauge(
            'Services Pending',
            fn() => DB::table('services')->where('status', 'pending')->count()
        );

        Prometheus::addGauge(
            'Services Suspended',
            fn() => DB::table('services')->where('status', 'suspended')->count()
        );

        Prometheus::addGauge(
            'Services Failed',
            fn() => DB::table('services')->where('status', 'failed')->count()
        );

        Prometheus::addGauge(
            'Services Expiring Soon',
            fn() => DB::table('services')
                ->where('status', 'active')
                ->whereBetween('next_due_date', [now(), now()->addDays(7)])
                ->count()
        );

        Prometheus::addGauge(
            'Services Expired',
            fn() => DB::table('services')
                ->where('status', 'active')
                ->where('next_due_date', '<', now())
                ->count()
        );

        // ── Game Servers ───────────────────────────────────────
        Prometheus::addGauge(
            'Game Servers Total',
            fn() => DB::table('services')
                ->whereNotNull('pterodactyl_server_id')
                ->count()
        );

        Prometheus::addGauge(
            'Game Servers Active',
            fn() => DB::table('services')
                ->where('status', 'active')
                ->whereNotNull('pterodactyl_server_id')
                ->count()
        );

        Prometheus::addGauge(
            'Game Servers Failed',
            fn() => DB::table('services')
                ->where('status', 'failed')
                ->whereNotNull('pterodactyl_server_id')
                ->count()
        );

        Prometheus::addGauge(
            'Game Servers Suspended',
            fn() => DB::table('services')
                ->where('status', 'suspended')
                ->whereNotNull('pterodactyl_server_id')
                ->count()
        );

        // ── Usuarios ───────────────────────────────────────────
        Prometheus::addGauge(
            'Users Total',
            fn() => DB::table('users')->count()
        );

        Prometheus::addGauge(
            'Users New Today',
            fn() => DB::table('users')
                ->whereDate('created_at', today())
                ->count()
        );

        Prometheus::addGauge(
            'Users New This Month',
            fn() => DB::table('users')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count()
        );

        Prometheus::addGauge(
            'Users Verified',
            fn() => DB::table('users')
                ->whereNotNull('email_verified_at')
                ->count()
        );

        // ── Ingresos ───────────────────────────────────────────
        Prometheus::addGauge(
            'Revenue Today',
            fn() => (float) DB::table('invoices')
                ->where('status', 'paid')
                ->whereDate('paid_at', today())
                ->sum('total')
        );

        Prometheus::addGauge(
            'Revenue This Month',
            fn() => (float) DB::table('invoices')
                ->where('status', 'paid')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('total')
        );

        Prometheus::addGauge(
            'Revenue This Year',
            fn() => (float) DB::table('invoices')
                ->where('status', 'paid')
                ->whereYear('paid_at', now()->year)
                ->sum('total')
        );

        // ── Facturas ───────────────────────────────────────────
        Prometheus::addGauge(
            'Invoices Paid This Month',
            fn() => DB::table('invoices')
                ->where('status', 'paid')
                ->whereMonth('paid_at', now()->month)
                ->count()
        );

        Prometheus::addGauge(
            'Invoices Pending',
            fn() => DB::table('invoices')
                ->where('status', 'pending')
                ->count()
        );

        Prometheus::addGauge(
            'Invoices Overdue',
            fn() => DB::table('invoices')
                ->where('status', 'pending')
                ->where('due_date', '<', now())
                ->count()
        );

        // ── Tickets de soporte ─────────────────────────────────
        Prometheus::addGauge(
            'Tickets Open',
            fn() => DB::table('tickets')
                ->where('status', 'open')
                ->count()
        );

        Prometheus::addGauge(
            'Tickets In Progress',
            fn() => DB::table('tickets')
                ->where('status', 'in_progress')
                ->count()
        );

        Prometheus::addGauge(
            'Tickets Closed Today',
            fn() => DB::table('tickets')
                ->where('status', 'closed')
                ->whereDate('updated_at', today())
                ->count()
        );

        // ── Queue ──────────────────────────────────────────────
        Prometheus::addGauge(
            'Queue Jobs Pending',
            fn() => DB::table('jobs')->count()
        );

        Prometheus::addGauge(
            'Queue Jobs Failed',
            fn() => DB::table('failed_jobs')->count()
        );

        // ── Laravel ────────────────────────────────────────────
        Prometheus::addGauge(
            'Laravel Up',
            fn() => 1
        );
    }


    public function registerHorizonCollectors(): self
    {
        Prometheus::registerCollectorClasses([
            CurrentMasterSupervisorCollector::class,
            CurrentProcessesPerQueueCollector::class,
            CurrentWorkloadCollector::class,
            FailedJobsPerHourCollector::class,
            HorizonStatusCollector::class,
            JobsPerMinuteCollector::class,
            RecentJobsCollector::class,
        ]);

        return $this;
    }

    public function registerQueueCollectors(array $queues = [], ?string $connection = null): self
    {
        Prometheus::registerCollectorClasses([
            QueueSizeCollector::class,
            QueuePendingJobsCollector::class,
            QueueDelayedJobsCollector::class,
            QueueReservedJobsCollector::class,
            QueueOldestPendingJobCollector::class,
        ], compact('connection', 'queues'));

        return $this;
    }
}
