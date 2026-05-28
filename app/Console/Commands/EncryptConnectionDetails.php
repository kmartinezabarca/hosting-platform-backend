<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Moves sensitive keys out of the queryable JSON connection_details column and
 * stores them in the encrypted connection_secrets column.
 */
class EncryptConnectionDetails extends Command
{
    protected $signature = 'services:encrypt-connection-details
                            {--dry-run : Muestra qué se haría sin modificar nada}
                            {--chunk=100 : Cuántos registros procesar a la vez}';

    protected $description = 'Migra secretos de connection_details a connection_secrets cifrado.';

    private const SECRET_KEYS = [
        'db_password',
        'ftp_password',
        'sftp_password',
        'ssh_password',
        'password',
        'api_token',
        'access_token',
        'secret_key',
        'private_key',
    ];

    public function handle(): int
    {
        if (! Schema::hasColumn('services', 'connection_secrets')) {
            $this->error('Falta la columna services.connection_secrets.');
            $this->line('Ejecuta primero: php artisan migrate');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        $this->info($dryRun
            ? '[DRY RUN] Se revisarían secretos sin modificar registros.'
            : 'Migrando secretos de connection_details a connection_secrets...'
        );

        $total = 0;
        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        DB::table('services')
            ->select(['id', 'connection_details', 'connection_secrets'])
            ->whereNotNull('connection_details')
            ->orderBy('id')
            ->chunk($chunkSize, function ($rows) use ($dryRun, &$total, &$migrated, &$skipped, &$errors) {
                foreach ($rows as $row) {
                    $total++;

                    $details = json_decode((string) $row->connection_details, true);
                    if (! is_array($details)) {
                        $this->warn("  ID {$row->id}: connection_details no es JSON valido. Saltando.");
                        $skipped++;
                        continue;
                    }

                    $secrets = [];
                    foreach (self::SECRET_KEYS as $key) {
                        if (array_key_exists($key, $details)) {
                            if ($details[$key] !== null && $details[$key] !== '') {
                                $secrets[$key] = $details[$key];
                            }

                            unset($details[$key]);
                        }
                    }

                    if ($secrets === []) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $existingSecrets = $this->decryptSecrets($row->connection_secrets);
                        $mergedSecrets = array_merge($existingSecrets, $secrets);

                        if ($dryRun) {
                            $this->line("  [DRY] ID {$row->id}: se migrarian " . implode(', ', array_keys($secrets)));
                            $migrated++;
                            continue;
                        }

                        DB::table('services')
                            ->where('id', $row->id)
                            ->update([
                                'connection_details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                'connection_secrets' => Crypt::encryptString(json_encode($mergedSecrets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                                'updated_at' => now(),
                            ]);

                        $migrated++;
                    } catch (Throwable $e) {
                        $this->error("  ID {$row->id}: error al migrar secretos - {$e->getMessage()}");
                        Log::error('EncryptConnectionDetails fallo', [
                            'service_id' => $row->id,
                            'error' => $e->getMessage(),
                        ]);
                        $errors++;
                    }
                }
            });

        $this->newLine();
        $this->table(
            ['Total', 'Migrados', 'Sin secretos', 'Errores'],
            [[$total, $migrated, $skipped, $errors]]
        );

        if ($errors > 0) {
            $this->error('Hubo errores. Revisa los logs.');
            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run completado.' : 'Migracion de secretos completada.');
        return self::SUCCESS;
    }

    private function decryptSecrets(?string $encrypted): array
    {
        if ($encrypted === null || $encrypted === '') {
            return [];
        }

        $decoded = json_decode(Crypt::decryptString($encrypted), true);

        return is_array($decoded) ? $decoded : [];

    }
}
