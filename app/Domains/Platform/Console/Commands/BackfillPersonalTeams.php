<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Compute\Enums\TeamRole;
use App\Domains\Platform\Compute\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea el equipo personal de cada usuario que aún no tenga uno.
 *
 * Idempotente: se puede correr las veces que sea; los usuarios que ya tienen
 * equipo personal se omiten. Para el registro de usuarios nuevos, el listener
 * de registro debe crear el equipo en línea — este comando es el backfill
 * histórico y la red de seguridad.
 *
 *   php artisan platform:compute:backfill-teams [--dry-run]
 */
class BackfillPersonalTeams extends Command
{
    protected $signature = 'platform:compute:backfill-teams {--dry-run : Solo reporta, no escribe}';

    protected $description = 'Crea un equipo personal (plano de cómputo) para cada usuario que no tenga uno';

    public function handle(): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $created = 0;
        $skipped = 0;

        User::query()
            ->whereDoesntHave('ownedTeams', fn ($q) => $q->where('is_personal', true))
            ->chunkById(200, function ($users) use ($dryRun, &$created, &$skipped) {
                foreach ($users as $user) {
                    $name = $user->username ?: trim((string) $user->first_name) ?: ('user-' . $user->id);

                    if ($dryRun) {
                        $this->line("[dry-run] crearía equipo personal para {$user->email} ({$name})");
                        $created++;
                        continue;
                    }

                    DB::transaction(function () use ($user, $name) {
                        $team = Team::create([
                            'name'          => $name,
                            'slug'          => $this->uniqueSlug($name),
                            'owner_user_id' => $user->id,
                            'is_personal'   => true,
                        ]);

                        $team->members()->attach($user->id, ['role' => TeamRole::Owner->value]);
                    });

                    $created++;
                }
            });

        $skipped = User::count() - $created;

        $this->info(($dryRun ? '[dry-run] ' : '')
            . "Equipos personales creados: {$created}. Usuarios ya cubiertos: {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Slug único global (teams.slug tiene unique). Colisiones entre usuarios
     * con el mismo nombre se resuelven con sufijo aleatorio corto.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'team';
        $slug = $base;

        while (Team::where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(4));
        }

        return $slug;
    }
}
