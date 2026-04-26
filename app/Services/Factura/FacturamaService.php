<?php

namespace App\Services\Factura;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente HTTP para la API de Facturama (CFDI 4.0).
 *
 * Documentación oficial: https://apisandbox.facturama.mx/docs
 *
 * Endpoints utilizados:
 *   POST   /3/cfdis               → Crear CFDI
 *   GET    /3/cfdis/{id}          → Obtener CFDI
 *   DELETE /2/cfdis/{id}/{motivo} → Cancelar CFDI
 *   GET    /cfdi/{format}/{type}/{id} → Descargar PDF / XML
 */
class FacturamaService
{
    private string $baseUrl;
    private string $user;
    private string $password;

    public function __construct()
    {
        $this->baseUrl  = rtrim(config('facturama.base_url'), '/');
        $this->user     = config('facturama.user', '');
        $this->password = config('facturama.password', '');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CFDI — Crear
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea y timbra un CFDI 4.0.
     *
     * @param  array $payload  Datos del comprobante (ver CfdiService::buildPayload)
     * @return array           Respuesta de Facturama con Id, CfdiType, Uuid, etc.
     * @throws RuntimeException si el timbrado falla
     */
    public function createCfdi(array $payload): array
    {
        $response = $this->http()->post('/3/cfdis', $payload);

        if ($response->failed()) {
            $this->logError('createCfdi', $response, $payload);
            throw new RuntimeException($this->parseError($response));
        }

        return $response->json();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CFDI — Obtener
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Obtiene los datos de un CFDI por su ID interno de Facturama.
     */
    public function getCfdi(string $facturamaId): array
    {
        $response = $this->http()->get("/3/cfdis/{$facturamaId}");

        if ($response->failed()) {
            $this->logError('getCfdi', $response);
            throw new RuntimeException($this->parseError($response));
        }

        return $response->json();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CFDI — Cancelar
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cancela un CFDI timbrado.
     *
     * @param string $facturamaId       ID interno de Facturama
     * @param string $motivo            Código SAT: 01,02,03,04
     *                                  01 = Comprobante emitido con errores con relación
     *                                  02 = Comprobante emitido con errores sin relación
     *                                  03 = No se llevó a cabo la operación
     *                                  04 = Operación nominativa relacionada en una factura global
     * @param string|null $folioSust    UUID del CFDI sustituto (solo para motivo 01)
     */
    public function cancelCfdi(string $facturamaId, string $motivo = '02', ?string $folioSust = null): array
    {
        $url = "/2/cfdis/{$facturamaId}/{$motivo}";
        if ($folioSust) {
            $url .= "/{$folioSust}";
        }

        $response = $this->http()->delete($url);

        if ($response->failed()) {
            $this->logError('cancelCfdi', $response);
            throw new RuntimeException($this->parseError($response));
        }

        return $response->json() ?? [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Descargas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Descarga el PDF del CFDI en base64.
     *
     * @param  string $facturamaId
     * @return string  Contenido en base64
     */
    public function downloadPdfBase64(string $facturamaId): string
    {
        $response = $this->http()->get("/cfdi/pdf/issued/{$facturamaId}");

        if ($response->failed()) {
            throw new RuntimeException($this->parseError($response));
        }

        $data = $response->json();
        return $data['Content'] ?? throw new RuntimeException('Facturama no devolvió contenido PDF.');
    }

    /**
     * Descarga el XML del CFDI en base64.
     */
    public function downloadXmlBase64(string $facturamaId): string
    {
        $response = $this->http()->get("/cfdi/xml/issued/{$facturamaId}");

        if ($response->failed()) {
            throw new RuntimeException($this->parseError($response));
        }

        $data = $response->json();
        return $data['Content'] ?? throw new RuntimeException('Facturama no devolvió contenido XML.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    private function http()
    {
        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->user, $this->password)
            ->timeout(config('facturama.timeout', 30))
            ->acceptJson()
            ->asJson();
    }

    private function parseError(Response $response): string
    {
        $body = $response->json();

        // Facturama puede devolver el error en distintos campos según el endpoint
        if (is_array($body)) {
            return $body['message']
                ?? $body['Message']
                ?? $body['ModelState'][''] [0]
                ?? ($body['details'] ? implode('; ', (array) $body['details']) : null)
                ?? json_encode($body);
        }

        return "Error HTTP {$response->status()} de Facturama.";
    }

    private function logError(string $method, Response $response, array $payload = []): void
    {
        Log::error("FacturamaService::{$method} falló", [
            'status'  => $response->status(),
            'body'    => $response->body(),
            'payload' => $payload,
        ]);
    }
}
