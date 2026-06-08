<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Revenue / subscription analytics for the admin dashboard.
 *
 * NOTE on currency: amounts are summed as stored (no FX normalization). The
 * platform stores values in mixed currencies (USD/MXN); figures here are an
 * operational overview, not an accounting source of truth. `currency` is the
 * reporting label only.
 *
 * NOTE on revenue: revenue = completed Transactions of type `payment` in the
 * window (gross, by created_at). Refunds are tracked separately.
 */
class AnalyticsService
{
    private const CURRENCY = 'MXN';

    /**
     * @param  string  $range  one of 7d|30d|90d|12m
     */
    public function overview(string $range = '30d'): array
    {
        [$start, $end, $granularity, $buckets] = $this->resolveRange($range);
        $periodLength = $start->diffInDays($end) + 1;
        $prevStart    = (clone $start)->subDays($periodLength);
        $prevEnd      = (clone $start)->subSecond();

        // ---- Load datasets once ------------------------------------------------
        $payments = Transaction::query()
            ->where('type', 'payment')
            ->where('status', 'completed')
            ->where('created_at', '>=', $prevStart)
            ->where('created_at', '<=', $end)
            ->get(['id', 'amount', 'receipt_id', 'created_at']);

        $subscriptions = Subscription::query()
            ->get(['id', 'user_id', 'amount', 'billing_cycle', 'status', 'created_at', 'canceled_at', 'ends_at']);

        $newClients = User::query()
            ->where('role', 'client')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->get(['id', 'created_at']);

        // ---- Revenue (current vs previous period) ------------------------------
        $revenueCurrent  = $this->sumIn($payments, $start, $end);
        $revenuePrevious = $this->sumIn($payments, $prevStart, $prevEnd);

        // ---- MRR / ARR ---------------------------------------------------------
        $mrrNow   = $this->mrrAt($subscriptions, $end);
        $mrrStart = $this->mrrAt($subscriptions, $prevEnd);
        $arr      = $mrrNow * 12;

        // ---- Customers / subscriptions snapshot --------------------------------
        $activeSubs   = $subscriptions->where('status', 'active');
        $activeSubsCt = $activeSubs->count();
        $activeCustomers = $activeSubs->pluck('user_id')->unique()->count();

        // ---- Churn -------------------------------------------------------------
        $activeAtStart = $subscriptions->filter(fn ($s) =>
            $s->created_at && $s->created_at <= $start
            && (is_null($s->canceled_at) || $s->canceled_at > $start)
        )->count();
        $churnedInRange = $subscriptions->filter(fn ($s) =>
            $s->canceled_at && $s->canceled_at >= $start && $s->canceled_at <= $end
        )->count();
        $churnRate = $activeAtStart > 0 ? round($churnedInRange / $activeAtStart * 100, 2) : 0.0;

        // ---- ARPU / LTV --------------------------------------------------------
        $arpu = $activeCustomers > 0 ? round($mrrNow / $activeCustomers, 2) : 0.0;
        $ltv  = $churnRate > 0 ? round($arpu / ($churnRate / 100), 2) : 0.0;

        // ---- Time series -------------------------------------------------------
        [$revenueSeries, $customersSeries] = $this->buildSeries(
            $buckets, $granularity, $payments, $subscriptions, $newClients
        );

        return [
            'range'    => $range,
            'currency' => self::CURRENCY,

            'revenue_total'      => round($revenueCurrent, 2),
            'revenue_change_pct' => $this->changePct($revenueCurrent, $revenuePrevious),
            'mrr'                => round($mrrNow, 2),
            'mrr_change_pct'     => $this->changePct($mrrNow, $mrrStart),
            'arr'                => round($arr, 2),

            'churn_rate'           => $churnRate,
            'new_customers'        => $newClients->count(),
            'active_subscriptions' => $activeSubsCt,
            'arpu'                 => $arpu,
            'ltv'                  => $ltv,

            'revenue_series'      => $revenueSeries,
            'customers_series'    => $customersSeries,
            'plan_distribution'   => $this->planDistribution(),
            'revenue_by_category' => $this->revenueByCategory($start, $end),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array{0:Carbon,1:Carbon,2:string,3:array<int,array{key:string,date:string,start:Carbon,end:Carbon}>}
     */
    private function resolveRange(string $range): array
    {
        if ($range === '12m') {
            $end     = now()->endOfDay();
            $start   = now()->startOfMonth()->subMonths(11);
            $buckets = [];
            for ($i = 0; $i < 12; $i++) {
                $cursor = (clone $start)->addMonths($i);
                $buckets[] = [
                    'key'   => $cursor->format('Y-m'),
                    'date'  => $cursor->format('Y-m-01'),
                    'start' => (clone $cursor)->startOfMonth(),
                    'end'   => (clone $cursor)->endOfMonth(),
                ];
            }
            return [$start, $end, 'month', $buckets];
        }

        $days  = match ($range) {
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };
        $start = now()->subDays($days - 1)->startOfDay();
        $end   = now()->endOfDay();

        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $cursor = (clone $start)->addDays($i);
            $buckets[] = [
                'key'   => $cursor->format('Y-m-d'),
                'date'  => $cursor->format('Y-m-d'),
                'start' => (clone $cursor)->startOfDay(),
                'end'   => (clone $cursor)->endOfDay(),
            ];
        }
        return [$start, $end, 'day', $buckets];
    }

    private function sumIn(Collection $payments, Carbon $from, Carbon $to): float
    {
        return (float) $payments
            ->filter(fn ($t) => $t->created_at >= $from && $t->created_at <= $to)
            ->sum('amount');
    }

    /** Normalize a recurring amount to its monthly equivalent. */
    private function toMonthly(float $amount, ?string $cycle): float
    {
        return match ($cycle) {
            'yearly'  => $amount / 12,
            'weekly'  => $amount * 52 / 12,
            'daily'   => $amount * 365 / 12,
            default   => $amount, // monthly
        };
    }

    /** MRR contributed by subscriptions live as of the given date. */
    private function mrrAt(Collection $subscriptions, Carbon $date): float
    {
        return (float) $subscriptions
            ->filter(fn ($s) =>
                $s->created_at && $s->created_at <= $date
                && (is_null($s->canceled_at) || $s->canceled_at > $date)
                && (is_null($s->ends_at) || $s->ends_at > $date)
            )
            ->sum(fn ($s) => $this->toMonthly((float) $s->amount, $s->billing_cycle));
    }

    private function changePct(float $current, float $previous): float
    {
        if ($previous > 0) {
            return round(($current - $previous) / $previous * 100, 1);
        }
        return $current > 0 ? 100.0 : 0.0;
    }

    /**
     * @return array{0:array<int,array>,1:array<int,array>}
     */
    private function buildSeries(array $buckets, string $granularity, Collection $payments, Collection $subscriptions, Collection $newClients): array
    {
        $revenueSeries   = [];
        $customersSeries = [];

        foreach ($buckets as $b) {
            $revenue = (float) $payments
                ->filter(fn ($t) => $t->created_at >= $b['start'] && $t->created_at <= $b['end'])
                ->sum('amount');

            $newCount = $newClients
                ->filter(fn ($u) => $u->created_at >= $b['start'] && $u->created_at <= $b['end'])
                ->count();

            $churnedCount = $subscriptions
                ->filter(fn ($s) => $s->canceled_at && $s->canceled_at >= $b['start'] && $s->canceled_at <= $b['end'])
                ->count();

            $revenueSeries[] = [
                'date'              => $b['date'],
                'revenue'           => round($revenue, 2),
                'mrr'               => round($this->mrrAt($subscriptions, $b['end']), 2),
                'new_customers'     => $newCount,
                'churned_customers' => $churnedCount,
            ];

            $customersSeries[] = [
                'date'              => $b['date'],
                'new_customers'     => $newCount,
                'churned_customers' => $churnedCount,
            ];
        }

        return [$revenueSeries, $customersSeries];
    }

    /** Active services grouped by plan name. */
    private function planDistribution(): array
    {
        return Service::query()
            ->where('status', 'active')
            ->with('plan:id,name')
            ->get(['id', 'plan_id'])
            ->groupBy(fn ($s) => $s->plan?->name ?? 'Sin plan')
            ->map(fn ($group, $name) => ['name' => $name, 'value' => $group->count()])
            ->values()
            ->all();
    }

    /** Revenue (completed payments) grouped by service plan category. */
    private function revenueByCategory(Carbon $start, Carbon $end): array
    {
        return Transaction::query()
            ->where('type', 'payment')
            ->where('status', 'completed')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->with('invoice.service.plan.category:id,name')
            ->get(['id', 'amount', 'receipt_id', 'created_at'])
            ->groupBy(fn ($t) => $t->invoice?->service?->plan?->category?->name ?? 'Otros')
            ->map(fn ($group, $name) => ['name' => $name, 'value' => round((float) $group->sum('amount'), 2)])
            ->values()
            ->all();
    }
}
