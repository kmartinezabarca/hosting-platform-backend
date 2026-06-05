<?php

namespace App\Domains\Platform\Services\Hestia;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HestiaService
{
    public function deleteUser(string $username): void
    {
        $this->execute('v-delete-user', [$username]);
    }

    public function suspendUser(string $username): void
    {
        $this->execute('v-suspend-user', [$username]);
    }

    public function listDomains(string $username): array
    {
        $response = $this->execute('v-list-web-domains', [$username, 'json'], false);
        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function execute(string $command, array $arguments = [], bool $returnCode = true): string
    {
        $payload = $this->authPayload() + [
            'returncode' => $returnCode ? 'yes' : 'no',
            'cmd' => $command,
        ];

        foreach (array_values($arguments) as $index => $argument) {
            $payload['arg' . ($index + 1)] = $argument;
        }

        $response = Http::asForm()
            ->withoutVerifying()
            ->post(rtrim((string) config('hestia.base_url'), '/') . '/api/', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('hestia_request_failed');
        }

        $body = trim($response->body());

        if ($returnCode && $body !== '0') {
            throw new RuntimeException($body !== '' ? $body : 'hestia_command_failed');
        }

        return $response->body();
    }

    private function authPayload(): array
    {
        $hash = config('hestia.hash');

        if (! empty($hash)) {
            return ['hash' => $hash];
        }

        return [
            'user' => config('hestia.admin_user'),
            'password' => config('hestia.admin_password'),
        ];
    }
}
