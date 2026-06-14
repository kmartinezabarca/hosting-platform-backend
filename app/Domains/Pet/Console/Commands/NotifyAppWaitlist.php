<?php

namespace App\Domains\Pet\Console\Commands;

use App\Domains\Pet\Mail\PetAppLaunchMail;
use App\Domains\Pet\Models\AppWaitlistEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa por correo a la lista de espera de la app que el lanzamiento ya ocurrió.
 * Se corre UNA VEZ al publicar la app (no está en el scheduler).
 *
 *   php artisan rokepet:notify-waitlist           # envía
 *   php artisan rokepet:notify-waitlist --dry-run # solo muestra a cuántos iría
 *
 * Solo contempla leads con correo; los de solo teléfono se reportan aparte para
 * un seguimiento manual (no hay envío de SMS). Marca notified=true para no
 * reenviar si el comando se ejecuta de nuevo.
 */
class NotifyAppWaitlist extends Command
{
    protected $signature = 'rokepet:notify-waitlist {--dry-run : Mostrar a cuántos se enviaría sin enviar}';

    protected $description = 'Avisa por correo a la lista de espera que la app de ROKE PET ya está disponible.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $pending     = AppWaitlistEntry::where('notified', false);
        $withEmail   = (clone $pending)->whereNotNull('email')->count();
        $phoneOnly   = (clone $pending)->whereNull('email')->whereNotNull('phone')->count();

        $this->info("Pendientes con correo: {$withEmail} · solo teléfono (manual): {$phoneOnly}");

        if ($dryRun) {
            $this->warn('Dry-run: no se envió nada.');
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        AppWaitlistEntry::where('notified', false)
            ->whereNotNull('email')
            ->chunkById(200, function ($entries) use (&$sent, &$failed) {
                foreach ($entries as $entry) {
                    try {
                        Mail::to($entry->email)->send(new PetAppLaunchMail($entry->name));
                        $entry->forceFill(['notified' => true])->save();
                        $sent++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('notify-waitlist: fallo al enviar', [
                            'entry' => $entry->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Enviados: {$sent} · fallidos: {$failed}");
        if ($phoneOnly > 0) {
            $this->warn("Quedan {$phoneOnly} leads solo con teléfono — atiéndelos manualmente desde el panel.");
        }

        return self::SUCCESS;
    }
}
