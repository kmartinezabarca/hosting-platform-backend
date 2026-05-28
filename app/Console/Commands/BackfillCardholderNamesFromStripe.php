<?php

namespace App\Console\Commands;

use App\Models\PaymentMethod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentMethod as StripePaymentMethod;

/**
 * Rellena `cardholder_name` en payment_methods consultando la API de Stripe
 * para registros que no tienen ese dato (creados antes de que se capturasen
 * los billing_details del método de pago).
 *
 * Uso:
 *   php artisan payment-methods:backfill-cardholder-names
 *   php artisan payment-methods:backfill-cardholder-names --all   # fuerza todos los registros
 */
class BackfillCardholderNamesFromStripe extends Command
{
    protected $signature = 'payment-methods:backfill-cardholder-names
                            {--all : Actualizar también los que ya tienen nombre}
                            {--dry-run : Solo muestra qué se actualizaría, sin persistir}';

    protected $description = 'Rellena cardholder_name consultando billing_details en Stripe';

    public function handle(): int
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        $query = PaymentMethod::query()
            ->whereNotNull('stripe_payment_method_id');

        if (!$this->option('all')) {
            $query->whereNull('cardholder_name');
        }

        $methods = $query->get(['id', 'stripe_payment_method_id', 'cardholder_name']);

        if ($methods->isEmpty()) {
            $this->info('No hay registros que actualizar.');
            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;
        $dryRun  = $this->option('dry-run');

        $bar = $this->output->createProgressBar($methods->count());
        $bar->start();

        foreach ($methods as $method) {
            try {
                $pm   = StripePaymentMethod::retrieve($method->stripe_payment_method_id);
                $name = $pm->billing_details->name ?? null;

                if ($name) {
                    if (!$dryRun) {
                        $method->update(['cardholder_name' => $name]);
                    }
                    $this->newLine();
                    $this->line("  ✓ [{$method->id}] {$method->stripe_payment_method_id} → {$name}" . ($dryRun ? ' [dry-run]' : ''));
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("  ✗ [{$method->id}] {$method->stripe_payment_method_id}: {$e->getMessage()}");
                Log::warning('BackfillCardholderNames: error al consultar Stripe', [
                    'payment_method_id' => $method->id,
                    'stripe_id'         => $method->stripe_payment_method_id,
                    'error'             => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Actualizados: {$updated} | Sin nombre en Stripe: {$skipped}" . ($dryRun ? ' [dry-run — nada persistido]' : ''));

        return self::SUCCESS;
    }
}
