<?php

namespace App\Services\Pet;

use App\Models\Pet\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushNotificationService
{
    private ?WebPush $webPush = null;

    private function getWebPush(): WebPush
    {
        if ($this->webPush === null) {
            // En local desactivar verificación SSL para alcanzar FCM/Mozilla Push
            // WebPush usa Guzzle — las opciones HTTP van en el 4º parámetro
            $clientOptions = app()->environment('local')
                ? ['verify' => false]
                : [];

            $this->webPush = new WebPush([
                'VAPID' => [
                    'subject'    => config('services.rokepet.vapid_subject', 'mailto:hola@roke.pet'),
                    'publicKey'  => config('services.rokepet.vapid_public_key'),
                    'privateKey' => config('services.rokepet.vapid_private_key'),
                ],
            ], [], 30, $clientOptions);
        }

        return $this->webPush;
    }

    /**
     * Envía una notificación push a todas las suscripciones activas del propietario.
     *
     * @return array{ sent: int, expired: int, failed: int, errors: string[] }
     */
    public function sendToOwnerDetailed(string $ownerId, string $title, string $body, array $data = []): array
    {
        $subscriptions = PushSubscription::where('owner_id', $ownerId)->get();

        if ($subscriptions->isEmpty()) {
            return ['sent' => 0, 'expired' => 0, 'failed' => 0, 'errors' => []];
        }

        $payload = json_encode(compact('title', 'body', 'data'));

        foreach ($subscriptions as $sub) {
            $this->getWebPush()->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'keys'     => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                ]),
                $payload
            );
        }

        $sent    = 0;
        $expired = 0;
        $failed  = 0;
        $errors  = [];

        foreach ($this->getWebPush()->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
            } elseif ($report->isSubscriptionExpired()) {
                $expired++;
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            } else {
                $failed++;
                $reason = $report->getReason();
                if ($reason) $errors[] = $reason;
            }
        }

        return compact('sent', 'expired', 'failed', 'errors');
    }

    /**
     * Versión simplificada — retorna solo el número enviado exitosamente.
     * Mantiene compatibilidad con el código existente.
     */
    public function sendToOwner(string $ownerId, string $title, string $body, array $data = []): int
    {
        return $this->sendToOwnerDetailed($ownerId, $title, $body, $data)['sent'];
    }

    /**
     * Envía a múltiples propietarios. Retorna el total enviado.
     */
    public function sendToMany(array $ownerIds, string $title, string $body, array $data = []): int
    {
        $total = 0;
        foreach ($ownerIds as $ownerId) {
            $total += $this->sendToOwner($ownerId, $title, $body, $data);
        }
        return $total;
    }
}
