<?php

namespace App\Domains\Platform\Services\GameServers\Contracts;

/**
 * Panel de servidores de juego (Pterodactyl hoy; Pelican u otro a futuro).
 *
 * Abstrae las operaciones de ciclo de vida sobre un servidor YA creado, que son
 * uniformes entre paneles (ambos exponen API admin + client + Wings). El cliente
 * de ROKE nunca ve el panel: ROKE lo maneja por API detrás de este contrato.
 *
 * Fase 1 (seam): solo lifecycle de un servidor existente. La CREACIÓN
 * (egg/nodo/allocation) queda para la fase 2 — es lo más acoplado al panel y al
 * flujo de provisión v1, y su spec neutral se diseña cuando se migren los callers.
 *
 * Convención de IDs (compartida por Pterodactyl/Pelican):
 *  - $serverId (int): id numérico del servidor (API de aplicación/admin).
 *  - $identifier (string): id corto del servidor (API de cliente / Wings).
 */
interface GameServerDriver
{
    /** Identificador del proveedor para logs/telemetría: 'pterodactyl' | 'pelican'. */
    public function name(): string;

    /** @return array detalles/estado del servidor (admin API). */
    public function getServer(int $serverId): array;

    public function suspendServer(int $serverId): void;

    public function unsuspendServer(int $serverId): void;

    public function reinstallServer(int $serverId): void;

    public function deleteServer(int $serverId, bool $force = true): void;

    /** Señal de power: start | stop | restart | kill. */
    public function sendPowerSignal(string $identifier, string $signal): void;

    /** @return array métricas de runtime del servidor (cpu/ram/disk/estado). */
    public function getServerResources(string $identifier): array;
}
