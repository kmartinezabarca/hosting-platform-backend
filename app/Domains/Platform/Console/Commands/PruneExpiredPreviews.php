<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Compute\Models\Environment;
use App\Domains\Platform\Compute\Previews\PreviewEnvironmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Barre ambientes preview efímeros vencidos (mes 2 #1). Red de seguridad por
 * si el webhook de cierre del PR nunca llegó: cada preview tiene expires_at y
 * aquí se destruye igual que en el teardown normal.
 */
class PruneExpiredPreviews extends Command
{
    protected $signature   = 'compute:prune-previews {--dry-run : Lista sin destruir}';
    protected $description = 'Destruye ambientes preview de PR vencidos (expires_at en el pasado).';

    public function handle(PreviewEnvironmentService $previews): int
    {
        $expired = Environment::where('ephemeral', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('project')
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No hay previews vencidos.');
            return self::SUCCESS;
        }

        $this->info("Previews vencidos: {$expired->count()}");

        foreach ($expired as $env) {
            $this->line("  [{$env->slug}] proyecto {$env->project?->slug} — venció {$env->expires_at}");

            if ($this->option('dry-run') || ! $env->project || ! $env->pr_number) {
                continue;
            }

            try {
                $previews->teardown($env->project, $env->pr_number);
                $this->line('    ✓ Destruido.');
            } catch (\Throwable $e) {
                $this->error("    ✗ {$e->getMessage()}");
                Log::error('Error al barrer preview vencido', ['env' => $env->slug, 'error' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }
}
