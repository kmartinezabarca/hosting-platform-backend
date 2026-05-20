<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Servicio central de respaldos.
 *
 * - Listado/eliminación/retención funcionan de forma uniforme para CUALQUIER
 *   tipo de respaldo, porque todo aterriza en el disco del NAS + tabla backups.
 * - La creación se delega por tipo: `platform` está implementado por completo
 *   (mysqldump + zip de storage). Los tipos de proveedor (game_server/hosting)
 *   son seams extensibles claros: registran el intento y fallan con un mensaje
 *   accionable hasta que se cablee la API del proveedor.
 */
class BackupService
{
    private string $disk;
    private string $root;

    public function __construct()
    {
        $this->disk = config('backup.disk', 'nas');
        $this->root = trim(config('backup.root', 'backups'), '/');
    }

    private function nas()
    {
        return Storage::disk($this->disk);
    }

    /* ───────────────────────── Listado ───────────────────────── */

    public function list(array $filters = [])
    {
        $q = Backup::query()->with(['user:id,first_name,last_name,email', 'service:id,uuid,name']);

        if (!empty($filters['type']))    $q->where('type', $filters['type']);
        if (!empty($filters['status']))  $q->where('status', $filters['status']);
        if (!empty($filters['user_id'])) $q->where('user_id', $filters['user_id']);
        if (!empty($filters['service_id'])) $q->where('service_id', $filters['service_id']);
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%"));
        }

        return $q->orderByDesc('created_at')
                 ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /* ───────────────────────── Eliminación ───────────────────────── */

    public function delete(Backup $backup): void
    {
        try {
            if ($backup->path && $this->nas()->exists($backup->path)) {
                $this->nas()->delete($backup->path);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo borrar el archivo de backup del NAS', [
                'backup' => $backup->uuid,
                'error'  => $e->getMessage(),
            ]);
        }
        $backup->delete();
    }

    /**
     * Borra varios backups por UUID. Devuelve cuántos se eliminaron.
     */
    public function bulkDelete(array $uuids): int
    {
        $count = 0;
        Backup::whereIn('uuid', $uuids)->get()->each(function (Backup $b) use (&$count) {
            $this->delete($b);
            $count++;
        });
        return $count;
    }

    /* ───────────────────────── Creación ───────────────────────── */

    /**
     * Crea el registro de respaldo en BD con status='pending' y despacha
     * el job al queue worker para ejecución en segundo plano.
     *
     * El frontend hace polling cada 15 s y muestra el estado en tiempo real.
     */
    public function create(string $type, array $opts = []): Backup
    {
        $backup = Backup::create([
            'name'        => $opts['name'] ?? $this->defaultName($type),
            'type'        => $type,
            'status'      => 'pending',
            'user_id'     => $opts['user_id'] ?? null,
            'service_id'  => $opts['service_id'] ?? null,
            'schedule_id' => $opts['schedule_id'] ?? null,
            'disk'        => $this->disk,
            'meta'        => $opts['meta'] ?? null,
        ]);

        \App\Jobs\ProcessBackupJob::dispatch($backup, $opts);

        return $backup;
    }

    /**
     * Ejecuta el driver de respaldo según el tipo.
     * Llamado por ProcessBackupJob desde el worker.
     *
     * @return array{path: string, size: int}
     */
    public function runType(string $type, Backup $backup, array $opts = []): array
    {
        return match ($type) {
            'platform'                                         => $this->backupPlatform($backup),
            'landing', 'portal_client', 'portal_admin', 'pet' => $this->backupProject($backup, $type),
            'client_files'                                     => $this->backupClientFiles($backup, $opts),
            'hosting'                                          => $this->backupHosting($backup, $opts),
            'game_server'                                      => $this->backupViaProvider($backup, 'game_server'),
            default                                            => throw new \InvalidArgumentException("Tipo de backup no soportado: {$type}"),
        };
    }

    /* ───────────────────────── Drivers ───────────────────────── */

    /**
     * Respaldo de plataforma: dump de la BD MySQL + zip del storage app.
     */
    private function backupPlatform(Backup $backup): array
    {
        $stamp = now()->format('Y-m-d_His');
        $tmpDir = storage_path('app/tmp-backups');
        @mkdir($tmpDir, 0775, true);

        $sqlFile = "{$tmpDir}/db_{$stamp}.sql";
        $zipFile = "{$tmpDir}/platform_{$stamp}.zip";

        // 1) Dump de la base de datos principal de la plataforma
        $cfg = config('database.connections.' . config('database.default'));
        $this->mysqldumpToFile([
            'host'     => $cfg['host'] ?? '127.0.0.1',
            'port'     => $cfg['port'] ?? 3306,
            'username' => $cfg['username'] ?? 'root',
            'password' => $cfg['password'] ?? '',
            'database' => $cfg['database'] ?? '',
        ], $sqlFile);

        // 2) Empaquetar SQL + storage/app/public en un zip
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sqlFile);
            throw new \RuntimeException('No se pudo crear el archivo zip.');
        }
        $zip->addFile($sqlFile, 'database.sql');
        $publicPath = storage_path('app/public');
        if (is_dir($publicPath)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($publicPath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $zip->addFile($file->getRealPath(), 'storage/' . ltrim(
                        str_replace($publicPath, '', $file->getRealPath()), '/\\'
                    ));
                }
            }
        }
        $zip->close();
        @unlink($sqlFile);

        // 3) Subir al NAS y limpiar temporal
        $remote = "{$this->root}/platform/" . basename($zipFile);
        $stream = fopen($zipFile, 'r');
        $this->nas()->writeStream($remote, $stream);
        if (is_resource($stream)) fclose($stream);
        $size = filesize($zipFile) ?: 0;
        @unlink($zipFile);

        return ['path' => $remote, 'size' => $size];
    }

    /**
     * Respaldo de proyecto interno (landing, portal_client, portal_admin, pet).
     *
     * Lee la configuración de backup.projects.{type} para saber:
     *   - source_path → directorio a empaquetar (opcional)
     *   - db          → nombre de la conexión Laravel a volcar (opcional)
     *
     * Al menos uno de los dos debe estar configurado.
     */
    private function backupProject(Backup $backup, string $type): array
    {
        $cfg        = config("backup.projects.{$type}", []);
        $sourcePath = $cfg['source_path'] ?? null;
        $dbConn     = $cfg['db'] ?? null;

        if (!$sourcePath && !$dbConn) {
            throw new \InvalidArgumentException(
                "El proyecto '{$type}' no tiene source_path ni db configurados. "
                . "Agrega " . strtoupper($type) . "_SOURCE_PATH al .env o configura la BD en config/backup.php."
            );
        }

        $stamp  = now()->format('Y-m-d_His');
        $tmpDir = storage_path('app/tmp-backups');
        @mkdir($tmpDir, 0775, true);
        $zipFile = "{$tmpDir}/{$type}_{$stamp}.zip";

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el archivo zip.');
        }

        // 1) Volcado de BD si está configurada
        if ($dbConn) {
            $conn    = config("database.connections.{$dbConn}");
            $sqlFile = "{$tmpDir}/{$type}_db_{$stamp}.sql";
            $this->mysqldumpToFile([
                'host'     => $conn['host'] ?? '127.0.0.1',
                'port'     => $conn['port'] ?? 3306,
                'username' => $conn['username'] ?? 'root',
                'password' => $conn['password'] ?? '',
                'database' => $conn['database'] ?? '',
            ], $sqlFile);
            $zip->addFile($sqlFile, 'database.sql');
        }

        // 2) Archivos del directorio de origen si está configurado
        if ($sourcePath && is_dir($sourcePath)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $zip->addFile($file->getRealPath(), 'files/' . ltrim(
                        str_replace($sourcePath, '', $file->getRealPath()), '/\\'
                    ));
                }
            }
        }

        $zip->close();
        if (isset($sqlFile)) @unlink($sqlFile);

        $remote = "{$this->root}/{$type}/" . basename($zipFile);
        $stream = fopen($zipFile, 'r');
        $this->nas()->writeStream($remote, $stream);
        if (is_resource($stream)) fclose($stream);
        $size = filesize($zipFile) ?: 0;
        @unlink($zipFile);

        return ['path' => $remote, 'size' => $size];
    }

    /**
     * Respaldo de archivos de un cliente: empaqueta una carpeta de origen
     * (en disco local del servidor o ya en el NAS) hacia la zona de backups.
     */
    private function backupClientFiles(Backup $backup, array $opts): array
    {
        $sourcePath = $opts['source_path'] ?? null;
        if (!$sourcePath || !is_dir($sourcePath)) {
            throw new \InvalidArgumentException(
                'Ruta de origen inválida para el respaldo de archivos del cliente.'
            );
        }

        $stamp = now()->format('Y-m-d_His');
        $tmpDir = storage_path('app/tmp-backups');
        @mkdir($tmpDir, 0775, true);
        $zipFile = "{$tmpDir}/client_{$backup->id}_{$stamp}.zip";

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el archivo zip.');
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getRealPath(), ltrim(
                    str_replace($sourcePath, '', $file->getRealPath()), '/\\'
                ));
            }
        }
        $zip->close();

        $owner = $backup->user_id ?: 'shared';
        $remote = "{$this->root}/clients/{$owner}/" . basename($zipFile);
        $stream = fopen($zipFile, 'r');
        $this->nas()->writeStream($remote, $stream);
        if (is_resource($stream)) fclose($stream);
        $size = filesize($zipFile) ?: 0;
        @unlink($zipFile);

        return ['path' => $remote, 'size' => $size];
    }

    /**
     * Respaldo de hosting (Coolify): vuelca la base de datos del sitio
     * usando las credenciales guardadas en connection_details y la sube
     * comprimida al NAS.
     */
    private function backupHosting(Backup $backup, array $opts): array
    {
        $conn = $opts['conn']
            ?? $backup->service?->connection_details
            ?? [];

        $dbName = $conn['db_name'] ?? null;
        if (!$dbName) {
            throw new \InvalidArgumentException(
                'El servicio de hosting no tiene una base de datos asociada para respaldar.'
            );
        }

        // Coolify puede entregar el host como "mysql://user:pass@host:port/db"
        // o como host plano. Normalizamos.
        $host = $conn['db_host'] ?? '127.0.0.1';
        $port = 3306;
        if (is_string($host) && str_contains($host, '://')) {
            $parts = parse_url($host);
            $host = $parts['host'] ?? $host;
            $port = $parts['port'] ?? 3306;
        }

        $stamp  = now()->format('Y-m-d_His');
        $tmpDir = storage_path('app/tmp-backups');
        @mkdir($tmpDir, 0775, true);
        $sqlFile = "{$tmpDir}/hosting_{$backup->id}_{$stamp}.sql";
        $zipFile = "{$tmpDir}/hosting_{$backup->id}_{$stamp}.zip";

        $this->mysqldumpToFile([
            'host'     => $host,
            'port'     => $port,
            'username' => $conn['db_user'] ?? '',
            'password' => $conn['db_password'] ?? '',
            'database' => $dbName,
        ], $sqlFile);

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sqlFile);
            throw new \RuntimeException('No se pudo crear el archivo zip.');
        }
        $zip->addFile($sqlFile, 'database.sql');
        $zip->close();
        @unlink($sqlFile);

        $owner  = $backup->user_id ?: 'shared';
        $remote = "{$this->root}/hosting/{$owner}/" . basename($zipFile);
        $stream = fopen($zipFile, 'r');
        $this->nas()->writeStream($remote, $stream);
        if (is_resource($stream)) fclose($stream);
        $size = filesize($zipFile) ?: 0;
        @unlink($zipFile);

        return ['path' => $remote, 'size' => $size];
    }

    /**
     * Seam para respaldos vía proveedor aún no cableados (game_server).
     * Los game servers usan la API nativa de Pterodactyl directamente desde
     * el ServiceController; este método solo cubre el camino programado.
     */
    private function backupViaProvider(Backup $backup, string $kind): array
    {
        throw new \RuntimeException(
            "La creación programada de respaldos '{$kind}' usa la API nativa "
            . 'del proveedor desde el panel del servicio, no este servicio.'
        );
    }

    /**
     * Ejecuta mysqldump contra una conexión arbitraria y escribe el .sql.
     *
     * @param array{host:string,port:int|string,username:string,password:string,database:string} $db
     */
    private function mysqldumpToFile(array $db, string $sqlFile): void
    {
        $process = new Process([
            config('backup.mysqldump_path', 'mysqldump'),
            '-h', (string) ($db['host'] ?? '127.0.0.1'),
            '-P', (string) ($db['port'] ?? 3306),
            '-u', (string) ($db['username'] ?? 'root'),
            '--password=' . ($db['password'] ?? ''),
            '--single-transaction', '--quick', '--no-tablespaces',
            (string) ($db['database'] ?? ''),
        ]);
        $process->setTimeout(600);

        $sql = fopen($sqlFile, 'w');
        $process->run(function ($t, $buffer) use ($sql) {
            if ($t === Process::OUT) fwrite($sql, $buffer);
        });
        fclose($sql);

        if (!$process->isSuccessful()) {
            @unlink($sqlFile);
            throw new \RuntimeException('mysqldump falló: ' . trim($process->getErrorOutput()));
        }
    }

    /* ───────────────────────── Escaneo NAS ───────────────────────── */

    /**
     * Recorre el disco NAS y registra en la BD los archivos que aún no
     * tienen un registro. Útil para importar respaldos pre-existentes.
     *
     * @return array{registered:int, skipped:int}
     */
    public function scanNas(): array
    {
        $disk     = $this->nas();
        $rootPath = ($this->root === '.' || $this->root === '') ? '' : rtrim($this->root, '/');

        // Limitar el escaneo a subdirectorios conocidos de respaldos para
        // evitar recorrer el NAS entero y causar un timeout 504.
        $scanDirs = config('backup.scan_dirs', [
            'platform', 'landing', 'portal_client', 'portal_admin', 'pet',
            'clients', 'hosting', 'game_server',
        ]);

        $files = [];
        foreach ($scanDirs as $dir) {
            $scanPath = $rootPath ? "{$rootPath}/{$dir}" : $dir;
            try {
                $found = $disk->allFiles($scanPath);
                $files = array_merge($files, $found);
            } catch (\Throwable) {
                // El directorio no existe aún — ignorar
            }
        }

        $registered = 0;
        $skipped    = 0;
        $validExts  = ['zip', 'sql', 'tar', 'gz', 'bz2', 'tgz'];

        foreach ($files as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            // Ignorar archivos que no son respaldos
            if (!in_array($ext, $validExts, true)) {
                $skipped++;
                continue;
            }

            // Ya registrado
            if (Backup::where('path', $path)->exists()) {
                $skipped++;
                continue;
            }

            $type = $this->inferTypeFromPath($path);
            $name = pathinfo($path, PATHINFO_FILENAME);
            $size = 0;

            try { $size = $disk->size($path); } catch (\Throwable) {}

            $fileDate = now();
            try {
                $ts = $disk->lastModified($path);
                if ($ts) $fileDate = Carbon::createFromTimestamp($ts);
            } catch (\Throwable) {}

            $backup = new Backup([
                'name'         => $name,
                'type'         => $type,
                'status'       => 'completed',
                'disk'         => $this->disk,
                'path'         => $path,
                'size_bytes'   => $size,
                'started_at'   => $fileDate,
                'completed_at' => $fileDate,
                'meta'         => ['scanned' => true, 'source' => 'nas_scan'],
            ]);

            // Preservar la fecha real del archivo en lugar de now()
            $backup->timestamps = false;
            $backup->created_at = $fileDate;
            $backup->updated_at = $fileDate;
            $backup->save();

            $registered++;
        }

        Log::info('scanNas completo', ['registered' => $registered, 'skipped' => $skipped]);

        return ['registered' => $registered, 'skipped' => $skipped];
    }

    /**
     * Infiere el tipo de respaldo a partir de la ruta del archivo en el NAS.
     *
     * Cubre tanto los directorios propios del sistema como los backups
     * pre-existentes detectados en la estructura real del NAS:
     *   dell/db/         → platform  (volcados SQL del cron externo)
     *   dell/configs/    → platform  (configs del sistema)
     *   platform/        → platform
     *   landing/         → landing
     *   portal_client/   → portal_client
     *   portal_admin/    → portal_admin
     *   pet/             → pet
     *   clients/         → client_files
     *   hosting/         → hosting
     *   game_server/     → game_server
     */
    private function inferTypeFromPath(string $path): string
    {
        $lower = strtolower($path);

        // Backups pre-existentes del NAS (scripts externos)
        if (str_contains($lower, 'dell/db/')      || str_contains($lower, 'dell/configs/')) return 'platform';

        // Directorios propios del sistema
        if (str_starts_with($lower, 'landing/')      || str_contains($lower, '/landing/'))      return 'landing';
        if (str_starts_with($lower, 'portal_client/') || str_contains($lower, '/portal_client/')) return 'portal_client';
        if (str_starts_with($lower, 'portal_admin/')  || str_contains($lower, '/portal_admin/'))  return 'portal_admin';
        if (str_starts_with($lower, 'pet/')           || str_contains($lower, '/pet/'))           return 'pet';
        if (str_starts_with($lower, 'clients/')       || str_contains($lower, '/clients/'))       return 'client_files';
        if (str_starts_with($lower, 'hosting/')       || str_contains($lower, '/hosting/'))       return 'hosting';
        if (str_starts_with($lower, 'game_server/')   || str_contains($lower, '/game_server/'))   return 'game_server';

        return 'platform';
    }

    /* ───────────────────────── Retención ───────────────────────── */

    public function applyRetention(?int $days = null): int
    {
        $days = $days ?? (int) config('backup.retention_days', 30);
        $cutoff = Carbon::now()->subDays($days);

        $old = Backup::where('created_at', '<', $cutoff)
            ->whereNull('schedule_id') // las de programación se purgan por su propia retención
            ->get();

        $n = 0;
        foreach ($old as $b) {
            $this->delete($b);
            $n++;
        }
        return $n;
    }

    private function defaultName(string $type): string
    {
        $labels = config('backup.types', []);
        $label = $labels[$type] ?? $type;
        return "{$label} — " . now()->format('Y-m-d H:i');
    }
}
