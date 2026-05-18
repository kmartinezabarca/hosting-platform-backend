<?php

namespace App\Console\Commands;

use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ExpireTrialServices extends Command
{
    protected $signature   = 'services:expire-trials
                                {--dry-run : Muestra qué se suspendería sin hacer cambios}';
    protected $description = 'Suspende servicios de prueba cuyo trial_ends_at ha vencido.';

    public function handle(): int
    {
        $isDry = $this->option('dry-run');

        $expired = Service::where('plan_type', 'trial')
            ->where('status', 'active')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->with(['user', 'plan.convertsToPlan'])
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No hay trials vencidos.');
            return self::SUCCESS;
        }

        $this->info("Trials vencidos encontrados: {$expired->count()}");

        foreach ($expired as $service) {
            $user = $service->user;
            $this->line("  [{$service->id}] {$service->name} — usuario: {$user?->email} — venció: {$service->trial_ends_at}");

            if ($isDry) {
                continue;
            }

            try {
                $service->update(['status' => 'suspended']);

                // Notificar al usuario
                if ($user) {
                    $convertsPlan = $service->plan?->convertsToPlan;

                    Notification::send($user, new \App\Notifications\ServiceNotification([
                        'title'   => 'Tu periodo de prueba ha terminado',
                        'message' => $convertsPlan
                            ? "Tu servicio '{$service->name}' ha sido suspendido. Actualiza al plan '{$convertsPlan->name}' para seguir disfrutándolo."
                            : "Tu servicio '{$service->name}' ha sido suspendido al finalizar el periodo de prueba.",
                        'type'    => 'service.trial_expired',
                        'data'    => [
                            'service_id'          => $service->uuid ?? $service->id,
                            'converts_to_plan_id' => $convertsPlan?->id,
                            'converts_to_plan'    => $convertsPlan?->only(['uuid', 'slug', 'name', 'base_price']),
                        ],
                    ]));
                }

                Log::info('Trial expirado y suspendido', [
                    'service_id' => $service->id,
                    'user_id'    => $user?->id,
                ]);

                $this->line("    ✓ Suspendido y notificado.");
            } catch (\Throwable $e) {
                $this->error("    ✗ Error: {$e->getMessage()}");
                Log::error('Error al expirar trial', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->info($isDry ? 'Dry-run completado. Sin cambios.' : "Completado: {$expired->count()} trial(s) procesados.");
        return self::SUCCESS;
    }
}
