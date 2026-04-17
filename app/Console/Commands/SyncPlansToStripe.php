<?php

namespace App\Console\Commands;

use App\Models\ServicePlan;
use App\Services\StripeSyncService;
use Illuminate\Console\Command;

/**
 * Artisan command: stripe:sync-plans
 *
 * Scans all active ServicePlan records and ensures each one has:
 *   1. A Stripe Product  (service_plans.stripe_product_id)
 *   2. A Stripe Price for every PlanPricing row  (plan_pricing.stripe_price_id)
 *   3. A fallback stripe_price_id on the plan itself (monthly or first available)
 *
 * Safe to run multiple times — already-synced plans are skipped.
 *
 * Usage:
 *   php artisan stripe:sync-plans               # sync only plans missing Stripe IDs
 *   php artisan stripe:sync-plans --all         # re-check every active plan
 *   php artisan stripe:sync-plans --plan=42     # sync a single plan by id/uuid/slug
 *   php artisan stripe:sync-plans --dry-run     # show what would be synced, no writes
 */
class SyncPlansToStripe extends Command
{
    protected $signature = 'stripe:sync-plans
                            {--all     : Re-check every active plan (not just incomplete ones)}
                            {--plan=   : Sync a single plan by id, uuid, or slug}
                            {--dry-run : Preview without making any Stripe API calls}';

    protected $description = 'Create Stripe Products & Prices for service plans that are missing them';

    public function handle(StripeSyncService $stripe): int
    {
        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — no Stripe API calls will be made.');
        }

        // ── Single-plan mode ─────────────────────────────────────────────────
        if ($identifier = $this->option('plan')) {
            $plan = ServicePlan::where('id', $identifier)
                ->orWhere('uuid', $identifier)
                ->orWhere('slug', $identifier)
                ->with(['pricing.billingCycle'])
                ->first();

            if (! $plan) {
                $this->error("Plan not found: {$identifier}");
                return self::FAILURE;
            }

            return $this->syncOne($stripe, $plan);
        }

        // ── Bulk mode ────────────────────────────────────────────────────────
        $query = ServicePlan::where('is_active', true)->with(['pricing.billingCycle']);

        if (! $this->option('all')) {
            // Only plans with incomplete Stripe data
            $query->where(function ($q) {
                $q->whereNull('stripe_product_id')
                  ->orWhereNull('stripe_price_id')
                  ->orWhereHas('pricing', fn($q2) => $q2->whereNull('stripe_price_id'));
            });
        }

        $plans = $query->get();

        if ($plans->isEmpty()) {
            $this->info('All active plans are already fully synced with Stripe. ✓');
            return self::SUCCESS;
        }

        $this->info("Plans to sync: {$plans->count()}");
        $this->newLine();

        $synced  = 0;
        $failed  = 0;

        foreach ($plans as $plan) {
            $result = $this->syncOne($stripe, $plan, silent: true);
            $result === self::SUCCESS ? $synced++ : $failed++;
        }

        $this->newLine();
        $this->info("Done — {$synced} plan(s) synced, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function syncOne(StripeSyncService $stripe, ServicePlan $plan, bool $silent = false): int
    {
        $label = "  [{$plan->id}] {$plan->name}";

        if ($this->option('dry-run')) {
            $missing = [];
            if (! $plan->stripe_product_id) {
                $missing[] = 'product';
            }
            $noPrices = $plan->pricing->where('stripe_price_id', null);
            foreach ($noPrices as $p) {
                $missing[] = 'price(' . ($p->billingCycle?->slug ?? $p->billing_cycle_id) . ')';
            }

            if (empty($missing)) {
                $this->line("{$label}  <fg=green>✓ already synced</>");
            } else {
                $this->line("{$label}  <fg=yellow>would create: " . implode(', ', $missing) . '</> ');
            }
            return self::SUCCESS;
        }

        try {
            $result = $stripe->syncPlan($plan);

            if (! $silent) {
                $this->line("{$label}  <fg=green>✓</> product={$result['stripe_product_id']}");
            } else {
                $this->line("{$label}  <fg=green>✓</> product={$result['stripe_product_id']}");
            }

            foreach ($result['prices'] as $p) {
                $cycle  = $p['billing_cycle'] ?? '?';
                $status = $p['status'];

                if ($status === 'already_synced') {
                    $this->line("       cycle={$cycle}  <fg=gray>already synced: {$p['stripe_price_id']}</>");
                } elseif ($status === 'created') {
                    $this->line("       cycle={$cycle}  <fg=green>created: {$p['stripe_price_id']}</>");
                } else {
                    $this->line("       cycle={$cycle}  <fg=red>FAILED: {$p['error']}</>");
                }
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("{$label}  FAILED: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
