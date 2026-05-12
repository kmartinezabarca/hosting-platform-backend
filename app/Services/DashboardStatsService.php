<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;

class DashboardStatsService
{
    // ──────────────────────────────────────────────
    // Aggregated Dashboard Stats
    // ──────────────────────────────────────────────

    public function getAll(): array
    {
        return [
            'users'           => $this->usersStats(),
            'services'        => $this->servicesStats(),
            'revenue'         => $this->revenueStats(),
            'invoices'        => $this->invoicesStats(),
            'tickets'         => $this->ticketsStats(),
            'plans'           => $this->plansStats(),
            'recent_activity' => $this->recentActivity(),
        ];
    }

    // ──────────────────────────────────────────────
    // Individual Stat Groups
    // ──────────────────────────────────────────────

    public function usersStats(): array
    {
        return [
            'total'          => User::count(),
            'active'         => User::where('status', 'active')->count(),
            'pending'        => User::where('status', 'pending_verification')->count(),
            'suspended'      => User::where('status', 'suspended')->count(),
            'new_this_month' => User::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            'growth_rate'    => $this->growthRate(User::class, 'created_at'),
        ];
    }

    public function servicesStats(): array
    {
        return [
            'total'          => Service::count(),
            'active'         => Service::where('status', 'active')->count(),
            'suspended'      => Service::where('status', 'suspended')->count(),
            'maintenance'    => Service::where('status', 'maintenance')->count(),
            'cancelled'      => Service::where('status', 'cancelled')->count(),
            'new_this_month' => Service::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            'growth_rate'    => $this->growthRate(Service::class, 'created_at'),
        ];
    }

    public function revenueStats(): array
    {
        return [
            'monthly'     => Invoice::where('status', 'paid')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('total'),
            'yearly'      => Invoice::where('status', 'paid')->whereYear('created_at', now()->year)->sum('total'),
            'currency'    => config('billing.currency', 'MXN'),
            'growth_rate' => $this->revenueGrowthRate(),
        ];
    }

    public function invoicesStats(): array
    {
        $total   = Invoice::count();
        $paid    = Invoice::where('status', 'paid')->count();
        $pending = Invoice::whereIn('status', ['sent', 'processing'])->count();
        $overdue = Invoice::where('status', 'overdue')->count();

        return [
            'total'          => $total,
            'paid'           => $paid,
            'pending'        => $pending,
            'overdue'        => $overdue,
            'cancelled'      => Invoice::where('status', 'cancelled')->count(),
            'total_amount'   => (float) Invoice::sum('total'),
            'pending_amount' => (float) Invoice::whereIn('status', ['sent', 'processing', 'overdue'])->sum('total'),
            'total_pending'  => (float) Invoice::whereIn('status', ['sent', 'processing', 'overdue'])->sum('total'),
            'paid_percent'   => $total > 0 ? round(($paid / $total) * 100, 1) : 0,
        ];
    }

    public function ticketsStats(): array
    {
        return [
            'total'             => Ticket::count(),
            'open'              => Ticket::where('status', 'open')->count(),
            'in_progress'       => Ticket::where('status', 'in_progress')->count(),
            'resolved'          => Ticket::whereIn('status', ['resolved', 'closed'])->count(),
            'closed'            => Ticket::where('status', 'closed')->count(),
            'high_priority'     => Ticket::where('priority', 'high')->count(),
            'urgent'            => Ticket::where('priority', 'urgent')->count(),
            'avg_response_time' => $this->avgResponseTime(),
            'growth_rate'       => $this->growthRate(Ticket::class, 'created_at'),
        ];
    }

    public function plansStats(): array
    {
        return [
            'total_plans'    => ServicePlan::count(),
            'active_plans'   => ServicePlan::where('is_active', true)->count(),
            'popular_plans'  => ServicePlan::where('is_popular', true)->count(),
            'total_addons'   => AddOn::count(),
            'active_addons'  => AddOn::where('is_active', true)->count(),
        ];
    }

    // ──────────────────────────────────────────────
    // Recent Activity Feed
    // ──────────────────────────────────────────────

    public function recentActivity(): \Illuminate\Support\Collection
    {
        $activities = collect();

        User::latest()->take(3)->get()->each(function (User $user) use ($activities) {
            $activities->push([
                'type'        => 'user_registered',
                'description' => "New user registered: {$user->full_name}",
                'time'        => $user->created_at->diffForHumans(),
                'timestamp'   => $user->created_at->timestamp,
                'icon'        => 'user-plus',
            ]);
        });

        Service::latest()->take(3)->get()->each(function (Service $service) use ($activities) {
            $activities->push([
                'type'        => 'service_created',
                'description' => "New service created: {$service->name}",
                'time'        => $service->created_at->diffForHumans(),
                'timestamp'   => $service->created_at->timestamp,
                'icon'        => 'server',
            ]);
        });

        Ticket::latest()->take(2)->get()->each(function (Ticket $ticket) use ($activities) {
            $activities->push([
                'type'        => 'ticket_created',
                'description' => "New ticket: {$ticket->subject}",
                'time'        => $ticket->created_at->diffForHumans(),
                'timestamp'   => $ticket->created_at->timestamp,
                'icon'        => 'help-circle',
            ]);
        });

        return $activities->sortByDesc('timestamp')->take(10)->values();
    }

    // ──────────────────────────────────────────────
    // Private Helpers
    // ──────────────────────────────────────────────

    private function growthRate(string $model, string $dateField, array $extraWhere = []): float
    {
        $now      = now();
        $prevMonth = $now->copy()->subMonth();

        $base = fn () => array_reduce(
            $extraWhere,
            fn ($q, $w) => $q->where(...$w),
            $model::query()
        );

        $thisMonth = $base()->whereMonth($dateField, $now->month)->whereYear($dateField, $now->year)->count();
        $lastMonth = $base()->whereMonth($dateField, $prevMonth->month)->whereYear($dateField, $prevMonth->year)->count();

        if ($lastMonth === 0) {
            return $thisMonth > 0 ? 100.0 : 0.0;
        }

        return round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1);
    }

    private function revenueGrowthRate(): float
    {
        $now       = now();
        $prevMonth = $now->copy()->subMonth();

        $thisMonth = Invoice::where('status', 'paid')
            ->whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->sum('total');

        $lastMonth = Invoice::where('status', 'paid')
            ->whereMonth('created_at', $prevMonth->month)
            ->whereYear('created_at', $prevMonth->year)
            ->sum('total');

        if ($lastMonth == 0) {
            return $thisMonth > 0 ? 100.0 : 0.0;
        }

        return round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1);
    }

    /**
     * Average time (in hours) from ticket creation to first staff reply.
     */
    private function avgResponseTime(): string
    {
        $avg = Ticket::join('ticket_replies', 'tickets.id', '=', 'ticket_replies.ticket_id')
            ->whereColumn('ticket_replies.created_at', '>', 'tickets.created_at')
            ->where('ticket_replies.is_internal', false) // is_internal=false means it's a public (staff) reply
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, tickets.created_at, ticket_replies.created_at)) as avg_minutes')
            ->value('avg_minutes');

        if ($avg === null) {
            return 'N/A';
        }

        $hours   = floor($avg / 60);
        $minutes = (int) ($avg % 60);

        return $hours > 0
            ? "{$hours}h {$minutes}m"
            : "{$minutes}m";
    }
}
