<?php

namespace App\Services\RokePet;

use App\Models\RokePet\PushSubscription;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de web push para roke.pet.
 *
 * Requiere: composer require minishlink/web-push
 *
 * Variables de entorno necesarias en .env:
 *   ROKEPET_VAPID_PUBLIC_KEY=
 *   ROKEPET_VAPID_PRIVATE_KEY=
 *   ROKEPET_VAPID_SUBJECT=mailto:soporte@rokeindustries.com
 *
 * Generar llaves VAPID:
 *   npx web-push generate-vapid-keys
 */
class PushNotificationService
{
    public function sendToOwner(string $ownerId, string $title, string $body, string $url = '/'): int
    {
        if (! class_exists(\Minishlink\WebPush\WebPush::class)) {
            Log::warning('[rokepet] minishlink/web-push no está instalado. Omitiendo push.');
            return 0;
        }

        $vapidPublic  = env('ROKEPET_VAPID_PUBLIC_KEY', '');
        $vapidPrivate = env('ROKEPET_VAPID_PRIVATE_KEY', '');
        $vapidSubject = env('ROKEPET_VAPID_SUBJECT', 'mailto:soporte@rokeindustries.com');

        if (! $vapidPublic || ! $vapidPrivate) {
            return 0;
        }

        $subscriptions = PushSubscription::where('owner_id', $ownerId)->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $auth = [
            'VAPID' => [
                'subject'    => $vapidSubject,
                'publicKey'  => $vapidPublic,
                'privateKey' => $vapidPrivate,
            ],
        ];

        $webPush = new \Minishlink\WebPush\WebPush($auth);
        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);

        foreach ($subscriptions as $sub) {
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint' => $sub->endpoint,
                'keys'     => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
            ]);
            $webPush->queueNotification($subscription, $payload);
        }

        $sent = 0;
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
            } else {
                // Suscripción inválida o expirada — eliminar
                if ($report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint', $report->getRequest()->getUri()->__toString())->delete();
                }
                Log::warning('[rokepet] Push falló', ['reason' => $report->getReason()]);
            }
        }

        return $sent;
    }
}
