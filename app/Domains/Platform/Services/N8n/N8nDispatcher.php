<?php

namespace App\Domains\Platform\Services\N8n;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispara eventos salientes hacia n8n (backend → n8n) para que n8n ejecute
 * acciones como enviar una plantilla de WhatsApp (recordatorios de pago,
 * vencimiento de dominios/servicios, etc.).
 *
 * Best-effort: si n8n está caído o no configurado, se registra y NO se lanza
 * excepción — nunca debe romper el flujo de negocio que lo invoca.
 */
class N8nDispatcher
{
    /**
     * Envía un evento a un webhook de n8n.
     *
     * @param string $event  Nombre del flujo/evento (se concatena a la URL base).
     *                       Ej: 'whatsapp-reminder' → {N8N_WEBHOOK_URL}/whatsapp-reminder
     * @param array  $payload Datos del evento.
     * @return bool  true si n8n respondió 2xx.
     */
    public function dispatch(string $event, array $payload): bool
    {
        if (! config('n8n.enabled')) {
            return false;
        }

        $base = rtrim((string) config('n8n.webhook_url'), '/');
        if ($base === '') {
            Log::warning('N8nDispatcher: N8N_WEBHOOK_URL vacío; evento omitido', ['event' => $event]);

            return false;
        }

        try {
            $response = Http::timeout((int) config('n8n.timeout', 15))
                ->withHeaders(['Authorization' => 'Bearer ' . config('n8n.secret')])
                ->acceptJson()
                ->post("{$base}/{$event}", $payload);

            if ($response->failed()) {
                Log::warning('N8nDispatcher: n8n respondió con error', [
                    'event'  => $event,
                    'status' => $response->status(),
                ]);
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('N8nDispatcher: fallo al invocar n8n (no fatal)', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
