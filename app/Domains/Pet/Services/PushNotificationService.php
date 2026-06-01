<?php

namespace App\Domains\Pet\Services;

use App\Domains\Pet\Models\PushSubscription;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Cache;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends push notifications via:
 *   - VAPID / Web Push  (browser subscriptions)
 *   - FCM HTTP v1 API   (Android / iOS Flutter app)
 *
 * FCM setup:
 *   1. In Firebase Console → Project Settings → Service Accounts
 *      → "Generate new private key" → save as storage/firebase-credentials.json
 *   2. Add to .env:
 *         FIREBASE_CREDENTIALS=firebase-credentials.json
 *         FCM_PROJECT_ID=hosting-plataform
 */
class PushNotificationService
{
    private ?WebPush $webPush  = null;
    private ?Client  $http     = null;

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Send to all active subscriptions for an owner (both web push and FCM).
     *
     * @return array{ sent: int, expired: int, failed: int, errors: string[] }
     */
    public function sendToOwnerDetailed(string $ownerId, string $title, string $body, array $data = []): array
    {
        $subscriptions = PushSubscription::where('owner_id', $ownerId)->get();

        if ($subscriptions->isEmpty()) {
            return ['sent' => 0, 'expired' => 0, 'failed' => 0, 'errors' => []];
        }

        $sent = $expired = $failed = 0;
        $errors = [];

        $webPushSubs = $subscriptions->where('type', 'webpush');
        $fcmSubs     = $subscriptions->where('type', 'fcm');

        // ── VAPID / Web Push ─────────────────────────────────────────────────
        if ($webPushSubs->isNotEmpty()) {
            $payload = json_encode(compact('title', 'body', 'data'));

            foreach ($webPushSubs as $sub) {
                $this->getWebPush()->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub->endpoint,
                        'keys'     => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth],
                    ]),
                    $payload
                );
            }

            foreach ($this->getWebPush()->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                } elseif ($report->isSubscriptionExpired()) {
                    $expired++;
                    PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                } else {
                    $failed++;
                    if ($reason = $report->getReason()) $errors[] = $reason;
                }
            }
        }

        // ── FCM HTTP v1 ──────────────────────────────────────────────────────
        foreach ($fcmSubs as $sub) {
            $result = $this->sendFcmV1($sub->endpoint, $title, $body, $data);
            if ($result === 'sent') {
                $sent++;
            } elseif ($result === 'expired') {
                $expired++;
                $sub->delete();
            } else {
                $failed++;
                if ($result) $errors[] = $result;
            }
        }

        return compact('sent', 'expired', 'failed', 'errors');
    }

    /** Simplified version — returns only the number sent. */
    public function sendToOwner(string $ownerId, string $title, string $body, array $data = []): int
    {
        return $this->sendToOwnerDetailed($ownerId, $title, $body, $data)['sent'];
    }

    /** Send to multiple owners. */
    public function sendToMany(array $ownerIds, string $title, string $body, array $data = []): int
    {
        $total = 0;
        foreach ($ownerIds as $ownerId) {
            $total += $this->sendToOwner($ownerId, $title, $body, $data);
        }
        return $total;
    }

    // ── FCM HTTP v1 ───────────────────────────────────────────────────────────

    /**
     * Send one FCM notification.
     * Returns 'sent', 'expired', or an error string.
     */
    private function sendFcmV1(string $fcmToken, string $title, string $body, array $data = []): string
    {
        $projectId = config('services.rokepet.fcm_project_id', 'hosting-plataform');

        try {
            $token = $this->getFcmAccessToken();

            $response = $this->getHttpClient()->post(
                "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
                [
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'message' => [
                            'token'        => $fcmToken,
                            'notification' => [
                                'title' => $title,
                                'body'  => $body,
                            ],
                            'data'    => array_map('strval', $data),
                            'android' => [
                                'priority'     => 'high',
                                'notification' => ['sound' => 'default'],
                            ],
                            'apns' => [
                                'payload' => [
                                    'aps' => [
                                        'sound' => 'default',
                                        'badge' => 1,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]
            );

            return 'sent';

        } catch (ClientException $e) {
            $code   = $e->getResponse()->getStatusCode();
            $body   = json_decode($e->getResponse()->getBody()->getContents(), true);
            $status = $body['error']['status'] ?? '';

            // Token invalid / unregistered → expired
            if (in_array($status, ['NOT_FOUND', 'UNREGISTERED'], true) || $code === 404) {
                return 'expired';
            }

            return $body['error']['message'] ?? $e->getMessage();

        } catch (\Throwable $e) {
            // If credentials are missing, log and skip silently
            \Illuminate\Support\Facades\Log::warning('FCM send failed: ' . $e->getMessage());
            return $e->getMessage();
        }
    }

    /**
     * Get a valid FCM OAuth 2.0 access token.
     * Cached for 58 minutes (token lifetime is 60 min).
     *
     * Requires a Firebase service-account JSON at:
     *   storage/{FIREBASE_CREDENTIALS}
     *
     * To generate:
     *   Firebase Console → Project Settings → Service Accounts
     *   → "Generate new private key" → save as storage/firebase-credentials.json
     */
    private function getFcmAccessToken(): string
    {
        return Cache::remember('fcm_v1_access_token', 3480, function () {
            $credentialsFile = config('services.rokepet.firebase_credentials');

            if (!$credentialsFile) {
                throw new \RuntimeException(
                    'Firebase credentials not configured. ' .
                    'Add FIREBASE_CREDENTIALS=firebase-credentials.json to .env and place the ' .
                    'service account JSON in storage/.'
                );
            }

            $path = storage_path($credentialsFile);
            if (!file_exists($path)) {
                throw new \RuntimeException("Firebase credentials file not found: {$path}");
            }

            $creds = json_decode(file_get_contents($path), true);

            // Build JWT for service account
            $now     = time();
            $header  = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $payload = $this->base64url(json_encode([
                'iss'   => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ]));

            $input = "{$header}.{$payload}";
            openssl_sign($input, $signature, $creds['private_key'], 'sha256WithRSAEncryption');
            $jwt = "{$input}." . $this->base64url($signature);

            // Exchange JWT for access token
            $response = $this->getHttpClient()->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ],
            ]);

            $json = json_decode($response->getBody()->getContents(), true);
            return $json['access_token'];
        });
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ── Lazily-initialised clients ─────────────────────────────────────────────

    private function getWebPush(): WebPush
    {
        if ($this->webPush === null) {
            $clientOptions = app()->environment('local') ? ['verify' => false] : [];
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

    private function getHttpClient(): Client
    {
        if ($this->http === null) {
            $options = app()->environment('local') ? ['verify' => false] : [];
            $this->http = new Client($options);
        }
        return $this->http;
    }
}
