<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Re-encripta las columnas que dependen de APP_KEY usando un par de claves
 * explícito (OLD_APP_KEY → NEW_APP_KEY), sin tocar la clave activa del proceso.
 *
 * Columnas cubiertas:
 *   - services.connection_secrets  (encryptString(json); tolera formatos legados)
 *   - users.two_factor_secret      (encrypt() serializado)
 *
 * Seguridad:
 *   - NUNCA imprime valores desencriptados.
 *   - --dry-run no muta nada; reporta qué haría.
 *   - --backup exporta los payloads CIFRADOS originales (cifrados con la clave
 *     vieja) a un JSON para poder revertir; no contiene texto plano.
 *   - Verifica el round-trip con la clave nueva después de escribir.
 *
 * Uso:
 *   OLD_APP_KEY=base64:... NEW_APP_KEY=base64:... php artisan security:rotate-app-key --dry-run
 *   OLD_APP_KEY=base64:... NEW_APP_KEY=base64:... php artisan security:rotate-app-key --backup=storage/app/key-rotation-backup.json
 *
 * Después de rotar los datos, actualiza APP_KEY=NEW_APP_KEY en el entorno y
 * reinicia los procesos (sesiones y cookies se invalidan: los usuarios deben
 * volver a iniciar sesión).
 */
class RotateAppKey extends Command
{
    protected $signature = 'security:rotate-app-key
        {--old= : APP_KEY anterior (base64:...); si se omite usa OLD_APP_KEY del entorno}
        {--new= : APP_KEY nueva (base64:...); si se omite usa NEW_APP_KEY del entorno}
        {--dry-run : No muta nada; solo reporta}
        {--backup= : Ruta de archivo JSON donde respaldar los payloads cifrados originales}
        {--chunk=200 : Tamaño de lote}';

    protected $description = 'Re-encripta connection_secrets y two_factor_secret de OLD_APP_KEY a NEW_APP_KEY.';

    private Encrypter $old;
    private Encrypter $new;

    public function handle(): int
    {
        // Preferir las opciones explícitas (--old/--new): funcionan aunque la
        // config esté cacheada (config:cache), donde env() del .env no se lee.
        // Fallback a las variables de entorno exportadas en el shell.
        $oldKey = $this->option('old') ?: env('OLD_APP_KEY');
        $newKey = $this->option('new') ?: env('NEW_APP_KEY');

        if (! $oldKey || ! $newKey) {
            $this->error('Faltan las llaves. Pásalas con --old= y --new=, o exporta OLD_APP_KEY/NEW_APP_KEY en el entorno. No se rota nada.');
            return self::FAILURE;
        }

        if ($oldKey === $newKey) {
            $this->error('OLD_APP_KEY y NEW_APP_KEY son idénticas. Nada que rotar.');
            return self::FAILURE;
        }

        $cipher = config('app.cipher', 'AES-256-CBC');

        try {
            $this->old = new Encrypter($this->parseKey($oldKey), $cipher);
            $this->new = new Encrypter($this->parseKey($newKey), $cipher);
        } catch (\Throwable $e) {
            $this->error('Clave inválida: ' . $e->getMessage());
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $backup = $this->option('backup');
        $chunk  = max(1, (int) $this->option('chunk'));

        $backupData = [];

        $stats = [
            'services.connection_secrets' => ['rotated' => 0, 'skipped_unreadable' => 0, 'skipped_empty' => 0],
            'users.two_factor_secret'     => ['rotated' => 0, 'skipped_unreadable' => 0, 'skipped_empty' => 0],
        ];

        // ── services.connection_secrets ─────────────────────────────────────
        DB::table('services')
            ->select(['id', 'connection_secrets'])
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$stats, &$backupData, $dryRun) {
                foreach ($rows as $row) {
                    $key = 'services.connection_secrets';

                    if ($row->connection_secrets === null || $row->connection_secrets === '') {
                        $stats[$key]['skipped_empty']++;
                        continue;
                    }

                    $plaintext = $this->decryptConnectionSecrets($row->connection_secrets);

                    if ($plaintext === null) {
                        $stats[$key]['skipped_unreadable']++;
                        $this->warn("  services #{$row->id}: connection_secrets ilegible con OLD_APP_KEY (se omite)");
                        continue;
                    }

                    $backupData['services'][$row->id] = $row->connection_secrets;

                    if (! $dryRun) {
                        $reEncrypted = $this->new->encryptString($plaintext);

                        DB::table('services')->where('id', $row->id)
                            ->update(['connection_secrets' => $reEncrypted]);

                        // Verificación round-trip con la clave nueva.
                        $check = DB::table('services')->where('id', $row->id)->value('connection_secrets');
                        if ($this->new->decryptString($check) !== $plaintext) {
                            throw new \RuntimeException("Verificación post-rotación falló en services #{$row->id} — abortando.");
                        }
                    }

                    $stats[$key]['rotated']++;
                }
            });

        // ── users.two_factor_secret ─────────────────────────────────────────
        DB::table('users')
            ->select(['id', 'two_factor_secret'])
            ->whereNotNull('two_factor_secret')
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$stats, &$backupData, $dryRun) {
                foreach ($rows as $row) {
                    $key = 'users.two_factor_secret';

                    if ($row->two_factor_secret === '') {
                        $stats[$key]['skipped_empty']++;
                        continue;
                    }

                    try {
                        // encrypt() serializa por defecto; respetar el formato.
                        $secret = $this->old->decrypt($row->two_factor_secret);
                    } catch (\Throwable) {
                        $stats[$key]['skipped_unreadable']++;
                        $this->warn("  users #{$row->id}: two_factor_secret ilegible con OLD_APP_KEY (se omite)");
                        continue;
                    }

                    $backupData['users'][$row->id] = $row->two_factor_secret;

                    if (! $dryRun) {
                        $reEncrypted = $this->new->encrypt($secret);

                        DB::table('users')->where('id', $row->id)
                            ->update(['two_factor_secret' => $reEncrypted]);

                        $check = DB::table('users')->where('id', $row->id)->value('two_factor_secret');
                        if ($this->new->decrypt($check) !== $secret) {
                            throw new \RuntimeException("Verificación post-rotación falló en users #{$row->id} — abortando.");
                        }
                    }

                    $stats[$key]['rotated']++;
                }
            });

        // ── Backup de payloads cifrados originales ──────────────────────────
        if ($backup && $backupData !== []) {
            File::ensureDirectoryExists(dirname($backup));
            File::put($backup, json_encode($backupData, JSON_PRETTY_PRINT));
            $this->info('Backup de payloads cifrados originales: ' . $backup);
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY-RUN] ' : '') . 'Resultado:');
        foreach ($stats as $column => $s) {
            $this->line(sprintf(
                '  %-32s rotadas: %d · vacías: %d · ilegibles: %d',
                $column, $s['rotated'], $s['skipped_empty'], $s['skipped_unreadable']
            ));
        }

        if (! $dryRun) {
            $this->newLine();
            $this->warn('Siguiente paso: actualizar APP_KEY=NEW_APP_KEY en el entorno y reiniciar PHP-FPM/queue/scheduler.');
            $this->warn('Las sesiones y cookies activas se invalidan: los usuarios deberán iniciar sesión de nuevo.');
        }

        return self::SUCCESS;
    }

    /**
     * Desencripta connection_secrets tolerando los 3 formatos históricos y
     * devuelve SIEMPRE el JSON canónico (string) o null si es ilegible.
     */
    private function decryptConnectionSecrets(string $value): ?string
    {
        // Formato canónico: encryptString(json)
        try {
            $json = $this->old->decryptString($value);
            if (json_decode($json, true) !== null) {
                return $json;
            }
        } catch (\Throwable) {
            // continuar
        }

        // Legado: encrypt(json) con serialización PHP
        try {
            $decrypted = $this->old->decrypt($value);
            if (is_array($decrypted)) {
                return json_encode($decrypted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (is_string($decrypted) && json_decode($decrypted, true) !== null) {
                return $decrypted;
            }
        } catch (\Throwable) {
            // continuar
        }

        // JSON plano sin cifrar (filas pre-encriptación): normalizar cifrándolo.
        if (json_decode($value, true) !== null) {
            return $value;
        }

        return null;
    }

    private function parseKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7));
        }

        return $key;
    }
}
