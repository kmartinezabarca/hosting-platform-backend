<?php

namespace App\Domains\Pet\Jobs;

use App\Domains\Pet\Models\NotificationLog;
use App\Domains\Pet\Services\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class RetryNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 60;

    public function __construct(private string $notificationLogId) {}

    public function handle(PushNotificationService $pushService): void
    {
        $lockKey = "notification_retry:{$this->notificationLogId}";

        $acquired = Cache::lock($lockKey, 120)->get(function () use ($pushService) {
            /** @var NotificationLog|null $log */
            $log = NotificationLog::find($this->notificationLogId);

            if (!$log || !$log->isRetryable()) {
                return;
            }

            if ($log->channel === 'push') {
                $this->retryPush($log, $pushService);
            }
        });

        if ($acquired === false) {
            // Another worker is already retrying — release back to queue
            $this->release(30);
        }
    }

    private function retryPush(NotificationLog $log, PushNotificationService $pushService): void
    {
        if (!$log->owner_id) {
            $log->markFailed('no_owner', 'Missing owner_id — cannot retry push');
            return;
        }

        try {
            $payload = $log->payload ?? [];
            $data    = $payload['data'] ?? [];

            $sent = $pushService->sendToOwner(
                $log->owner_id,
                $log->title,
                $log->body ?? '',
                $data,
            );

            if ($sent > 0) {
                $log->markSent();
            } else {
                $log->markFailed('no_subscriptions', 'No active push subscriptions for owner');
            }
        } catch (\Throwable $e) {
            $log->markFailed('exception', substr($e->getMessage(), 0, 500));
        }
    }
}
