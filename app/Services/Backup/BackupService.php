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

    public function create(string $type, array $opts = []): Backup
    {
        $name = $opts['name'] ?? $this->defaultName($type);

        $backup = Backup::create([
            'name'        => $name,
            'type'        => $type,
            'status'      => 'running',
            'user_id'     => $opts['user_id'] ?? null,
            'service_id'  => $opts['service_id'] ?? null,
            'schedule_id' => $opts['schedule_id'] ?? null,
            'disk'        => $this->disk,
            'started_at'  => now(),
            'meta'        => $opts['meta'] ?? null,
        ]);

        try {
            $result = match ($type) {
                'platform'     => $this->backupPlatform($backup),
                'client_files' => $this->backupClientFiles($backup, $opts),
                'hosting'      => $this->backupHosting($backup, $opts),
                'game_server'  => $this->backupViaProvider($backup, 'game_server'),
                default        => throw new \InvalidArgumentException("Tipo de backup no soportado: {$type}"),
            };

            $backup->update([
                'status'       => 'completed',
                'path'         => $result['path'],
                'size_bytes'   => $result['size'] ?? 0,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $backup->update([
                'status'       => 'failed',
                'error'        => Str::limit($e->getMessage(), 1000),
                'completed_at' => now(),
            ]);
            Log::error('Backup falló', ['type' => $type, 'error' => $e->getMessage()]);
        }

        return $backup->fresh();
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
