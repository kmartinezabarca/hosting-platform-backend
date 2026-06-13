<?php

namespace App\Domains\Pet\Jobs;

use App\Domains\Pet\Events\PetTipBroadcast;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\NotificationCampaign;
use App\Domains\Pet\Models\NotificationLog;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Services\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fan-out de una campaña de notificaciones a su audiencia (hoy: todos los
 * dueños). Por cada dueño crea la entrada de bandeja (inbox), envía push a sus
 * dispositivos y transmite `.tip.received` en vivo. Reusa el patrón ya probado
 * de AdminController (log + push + inbox).
 *
 * Idempotente: solo procesa campañas en estado scheduled/sending; al terminar
 * las deja en `sent`. El inbox se crea SIEMPRE; el push solo llega a quien tiene
 * dispositivos suscritos (los contadores cuentan dueños, no dispositivos).
 */
class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public readonly string $campaignId) {}

    public function handle(PushNotificationService $push): void
    {
        $campaign = NotificationCampaign::find($this->campaignId);
        if (! $campaign || ! $campaign->isDispatchable()) {
            return;
        }

        $campaign->forceFill([
            'status'     => NotificationCampaign::STATUS_SENDING,
            'started_at' => $campaign->started_at ?? now(),
        ])->save();

        $title = $campaign->icon ? trim($campaign->icon . ' ' . $campaign->title) : $campaign->title;
        $body  = $campaign->body;
        $url   = $campaign->url;
        $data  = ['type' => 'tip', 'category' => $campaign->category, 'url' => $url ?? '', 'campaign_id' => $campaign->id];

        $recipients = 0;
        $sent       = 0;
        $failed     = 0;

        Owner::query()->select('id')->chunkById(500, function ($owners) use (
            $push, $campaign, $title, $body, $url, $data, &$recipients, &$sent, &$failed
        ) {
            foreach ($owners as $owner) {
                $recipients++;
                $ownerId = $owner->id;

                // 1) Bandeja in-app — siempre, todos la ven en la campanita.
                InboxNotification::createForOwner(
                    ownerId:   $ownerId,
                    title:     $title,
                    body:      $body,
                    notifType: 'tip',
                    url:       $url,
                    tag:       'campaign-' . $campaign->id,
                );

                // 2) Push a los dispositivos del dueño (si tiene).
                $log = NotificationLog::create([
                    'project_id'        => 'roke_pet',
                    'owner_id'          => $ownerId,
                    'channel'           => 'push',
                    'provider'          => 'webpush',
                    'notification_type' => 'tip',
                    'title'             => $title,
                    'body'              => $body,
                    'payload'           => ['data' => $data],
                    'status'            => 'pending',
                    'max_attempts'      => 1,
                ]);

                try {
                    $result = $push->sendToOwnerDetailed($ownerId, $title, $body, $data);
                    if ($result['sent'] > 0) {
                        $sent++;
                        $log->markSent();
                    } elseif ($result['failed'] > 0) {
                        $failed++;
                        $log->markFailed('delivery_failed', 'campaign push failed');
                    } else {
                        // 0 dispositivos / todos expirados: no es fallo, solo inbox.
                        $log->markFailed('no_devices', 'sin dispositivos push activos');
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $log->markFailed('exception', substr($e->getMessage(), 0, 500));
                }

                // 3) Realtime: toast + refresco de campanita al instante.
                try {
                    event(new PetTipBroadcast($ownerId, $title, $body, $url));
                } catch (\Throwable $e) {
                    Log::warning('Campaign broadcast falló: ' . $e->getMessage());
                }
            }
        });

        $campaign->forceFill([
            'status'           => NotificationCampaign::STATUS_SENT,
            'recipients_total' => $recipients,
            'sent_count'       => $sent,
            'failed_count'     => $failed,
            'finished_at'      => now(),
        ])->save();
    }

    public function failed(\Throwable $e): void
    {
        $campaign = NotificationCampaign::find($this->campaignId);
        if ($campaign && $campaign->status !== NotificationCampaign::STATUS_SENT) {
            $campaign->forceFill([
                'status'      => NotificationCampaign::STATUS_FAILED,
                'finished_at' => now(),
            ])->save();
        }
    }
}
