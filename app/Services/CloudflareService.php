<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CloudflareService
{
    private string $token;
    private string $zoneId;
    private string $baseUrl = 'https://api.cloudflare.com/client/v4';

    public function __construct()
    {
        $this->token  = config('services.cloudflare.token');
        $this->zoneId = config('services.cloudflare.zone_id');
    }

    /**
     * Crea un registro SRV para un servidor Minecraft Java Edition.
     * El registro permite conectarse con solo el hostname (sin puerto).
     *
     * Ej: _minecraft._tcp.kmartinez → mc.rokeindustries.com:25565
     *
     * @return string  ID del registro DNS creado en Cloudflare
     */
    /**
     * Crea un registro CNAME para el subdominio base.
     *
     * @return string ID del registro DNS creado
     */
    public function createCnameRecord(string $subdomain, string $target): string
    {
        $response = $this->http()->post("zones/{$this->zoneId}/dns_records", [
            'type'    => 'CNAME',
            'name'    => $subdomain,
            'content' => $target,
            'ttl'     => 60,
            'proxied' => false,
        ]);

        return $this->extractId($response, 'createCnameRecord');
    }

    /**
     * Crea un registro SRV para un servidor Minecraft Java Edition.
     * El registro permite conectarse con solo el hostname (sin puerto).
     *
     * Ej: _minecraft._tcp.kmartinez → mc.rokeindustries.com:25565
     *
     * @return string  ID del registro DNS creado en Cloudflare
     */
    public function createMinecraftSrv(string $subdomain, int $port): string
    {
        $response = $this->http()->post("zones/{$this->zoneId}/dns_records", [
            'type' => 'SRV',
            'name' => "_minecraft._tcp.{$subdomain}",
            'data' => [
                'service'  => '_minecraft',
                'proto'    => '_tcp',
                'name'     => $subdomain,
                'priority' => 0,
                'weight'   => 5,
                'port'     => $port,
                'target'   => "{$subdomain}.rokeindustries.com",
            ],
            'ttl' => 60,
        ]);

        return $this->extractId($response, 'createMinecraftSrv');
    }

    /**
     * Crea un registro A para un servidor Bedrock (u otro que no use SRV).
     *
     * @return string  ID del registro DNS creado en Cloudflare
     */
    public function createARecord(string $subdomain, string $ip): string
    {
        $response = $this->http()->post("zones/{$this->zoneId}/dns_records", [
            'type'    => 'A',
            'name'    => $subdomain,
            'content' => $ip,
            'ttl'     => 60,
            'proxied' => false,
        ]);

        return $this->extractId($response, 'createARecord');
    }

    /**
     * Elimina un registro DNS por su ID.
     * No lanza excepción si falla — solo registra en el log.
     */
    public function deleteRecord(string $recordId): void
    {
        $response = $this->http()->delete("zones/{$this->zoneId}/dns_records/{$recordId}");

        if ($response->failed()) {
            Log::warning('CloudflareService::deleteRecord falló', [
                'record_id' => $recordId,
                'body'      => $response->body(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson();

        // En entornos locales/Windows el bundle de CA de PHP puede estar ausente,
        // lo que provoca "SSL certificate problem: unable to get local issuer certificate".
        // Solo desactivamos la verificación SSL fuera de producción para no afectar seguridad.
        if (! app()->isProduction()) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    private function extractId(\Illuminate\Http\Client\Response $response, string $method): string
    {
        if ($response->failed() || ! $response->json('success')) {
            $errors = collect($response->json('errors', []))->pluck('message')->implode(', ');
            Log::error("CloudflareService::{$method} falló", ['body' => $response->body()]);
            throw new RuntimeException("Cloudflare [{$method}]: {$errors}");
        }

        return $response->json('result.id');
    }
}
