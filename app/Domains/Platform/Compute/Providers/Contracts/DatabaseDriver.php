<?php

namespace App\Domains\Platform\Compute\Providers\Contracts;

use App\Domains\Platform\Compute\Models\Resource;

/**
 * Contrato del runtime de bases de datos administradas (MySQL/Postgres/Redis).
 * Separado de AppRuntimeDriver: el ciclo de vida de un data store (sin build,
 * con credenciales generadas) no se parece al de una app. ÚNICA capa que habla
 * con el proveedor; los pasos del orquestador dependen de esta interfaz.
 */
interface DatabaseDriver
{
    /**
     * Crea la base de datos en el proveedor y devuelve su id externo.
     *
     * $config: engine (mysql|postgres|redis), version?, name (db), ram_mb?.
     */
    public function createDatabase(Resource $resource, array $config): string;

    /**
     * Estado del data store en el runtime.
     *
     * @return array{status: string}  status normalizado: starting | running | failed
     */
    public function getDatabase(string $externalId): array;

    /**
     * Datos de conexión INTERNA (app→db dentro de la red del proveedor).
     *
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public function connectionInfo(string $externalId): array;

    public function startDatabase(string $externalId): void;

    public function deleteDatabase(string $externalId): void;
}
