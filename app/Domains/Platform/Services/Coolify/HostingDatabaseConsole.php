<?php

namespace App\Domains\Platform\Services\Coolify;

use App\Domains\Platform\Models\Service;
use PDO;
use RuntimeException;

/**
 * Gateway del gestor de base de datos NATIVO del portal.
 *
 * El cliente administra su DB desde el portal de ROKE (no desde Coolify ni una
 * herramienta externa). El backend de Laravel abre una conexión PDO a la base
 * del cliente y proxea las operaciones (listar tablas, leer filas, ejecutar SQL).
 *
 * Conectividad: la DB corre en un contenedor del nodo Coolify (Ryzen). El host
 * interno de Docker NO es alcanzable desde el servidor de Laravel, así que la
 * conexión usa la dirección publicada en la red privada (Tailscale):
 *   host  = connection_details.db_gateway_host  ?? config('coolify.db_gateway_host')
 *   port  = connection_details.db_public_port   (el puerto publicado por Coolify)
 * Si falta esa configuración, se devuelve un error claro (no se inventa nada).
 */
class HostingDatabaseConsole
{
    /** Filas por página al navegar una tabla. */
    public const PER_PAGE = 50;

    /** Tope duro de filas devueltas por una consulta SQL libre. */
    private const MAX_QUERY_ROWS = 500;

    /** Timeout de conexión/consulta en segundos. */
    private const TIMEOUT = 5;

    /** @return list<array{name:string, rows:int}> */
    public function tables(Service $service): array
    {
        $pdo = $this->connect($service);
        [$type, , , $db] = $this->target($service);

        if ($type === 'postgresql') {
            $stmt = $pdo->query(
                "SELECT table_name AS name FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name"
            );

            return array_map(fn ($r) => ['name' => $r['name'], 'rows' => 0], $stmt->fetchAll());
        }

        $stmt = $pdo->prepare(
            'SELECT table_name AS name, table_rows AS rows FROM information_schema.tables
             WHERE table_schema = :db ORDER BY table_name'
        );
        $stmt->execute(['db' => $db]);

        return array_map(fn ($r) => [
            'name' => (string) $r['name'],
            'rows' => (int) ($r['rows'] ?? 0),
        ], $stmt->fetchAll());
    }

    /**
     * Filas paginadas de una tabla.
     *
     * @return array{table:string, columns:list<string>, rows:list<array<string,mixed>>, page:int, per_page:int, total:int}
     */
    public function rows(Service $service, string $table, int $page = 1): array
    {
        $pdo = $this->connect($service);
        $table = $this->assertKnownTable($service, $pdo, $table);
        $quoted = $this->quoteIdent($service, $table);

        $page    = max(1, $page);
        $offset  = ($page - 1) * self::PER_PAGE;

        $total = (int) $pdo->query("SELECT COUNT(*) FROM {$quoted}")->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM {$quoted} LIMIT :limit OFFSET :offset");
        $stmt->bindValue('limit', self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll();

        $columns = $data ? array_keys($data[0]) : $this->columnNames($pdo, $quoted);

        return [
            'table'    => $table,
            'columns'  => $columns,
            'rows'     => $data,
            'page'     => $page,
            'per_page' => self::PER_PAGE,
            'total'    => $total,
        ];
    }

    /**
     * Ejecuta una consulta SQL libre en la base del cliente. Los errores de SQL
     * se devuelven en 'error' (no lanzan) para mostrarlos en la consola; solo
     * los problemas de conexión lanzan excepción.
     *
     * @return array{columns:list<string>, rows:list<array<string,mixed>>, affected:?int, truncated:bool, error:?string}
     */
    public function runQuery(Service $service, string $sql): array
    {
        $pdo = $this->connect($service);
        $base = ['columns' => [], 'rows' => [], 'affected' => null, 'truncated' => false, 'error' => null];

        try {
            $stmt = $pdo->query($sql);
        } catch (\Throwable $e) {
            return [...$base, 'error' => $e->getMessage()];
        }

        // Sentencias sin resultset (INSERT/UPDATE/DELETE/DDL).
        if ($stmt->columnCount() === 0) {
            return [...$base, 'affected' => $stmt->rowCount()];
        }

        $rows = [];
        $truncated = false;
        while (($row = $stmt->fetch()) !== false) {
            if (count($rows) >= self::MAX_QUERY_ROWS) {
                $truncated = true;
                break;
            }
            $rows[] = $row;
        }

        return [
            ...$base,
            'columns'   => $rows ? array_keys($rows[0]) : [],
            'rows'      => $rows,
            'truncated' => $truncated,
        ];
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Resuelve el destino de conexión alcanzable desde el backend.
     *
     * @return array{0:string,1:string,2:int,3:string} [type, host, port, dbname]
     */
    private function target(Service $service): array
    {
        $conn = $service->connection_details ?? [];
        $type = $conn['db_type'] ?? 'mariadb';
        $db   = $conn['db_name'] ?? null;

        // Host alcanzable (Tailscale), NO el host interno de Docker.
        $host = $conn['db_gateway_host'] ?? config('coolify.db_gateway_host');
        $port = isset($conn['db_public_port']) ? (int) $conn['db_public_port'] : null;

        if (! $host || ! $port) {
            throw new RuntimeException(
                'El gestor de base de datos aún no está habilitado para este servicio. '
                . 'Falta publicar el puerto de la base en la red privada (Tailscale) y registrar host/puerto.'
            );
        }

        if (! $db) {
            throw new RuntimeException('No se encontró el nombre de la base de datos del servicio.');
        }

        return [$type, (string) $host, $port, (string) $db];
    }

    private function connect(Service $service): PDO
    {
        [$type, $host, $port, $db] = $this->target($service);

        $conn    = $service->connection_details ?? [];
        $secrets = $service->connection_secrets ?? [];
        $user    = $conn['db_user'] ?? null;
        $pass    = $secrets['db_password'] ?? null;

        if (! $user) {
            throw new RuntimeException('No se encontraron las credenciales de la base de datos.');
        }

        $dsn = $type === 'postgresql'
            ? "pgsql:host={$host};port={$port};dbname={$db}"
            : "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => self::TIMEOUT,
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No se pudo conectar con la base de datos. Verifica que el servicio esté en línea '
                . 'y que el puerto esté publicado en la red privada.'
            );
        }
    }

    /** Valida que la tabla exista (evita inyección por el nombre de tabla). */
    private function assertKnownTable(Service $service, PDO $pdo, string $table): string
    {
        $known = array_column($this->tables($service), 'name');
        if (! in_array($table, $known, true)) {
            throw new RuntimeException("La tabla «{$table}» no existe en la base de datos.");
        }

        return $table;
    }

    private function quoteIdent(Service $service, string $ident): string
    {
        $type = ($service->connection_details['db_type'] ?? 'mariadb');
        // El identificador ya fue validado contra la lista real de tablas.
        return $type === 'postgresql' ? '"' . str_replace('"', '""', $ident) . '"'
                                      : '`' . str_replace('`', '``', $ident) . '`';
    }

    /** @return list<string> */
    private function columnNames(PDO $pdo, string $quotedTable): array
    {
        try {
            $stmt = $pdo->query("SELECT * FROM {$quotedTable} LIMIT 0");
            $cols = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $cols[] = $stmt->getColumnMeta($i)['name'] ?? "col_{$i}";
            }

            return $cols;
        } catch (\Throwable) {
            return [];
        }
    }
}
