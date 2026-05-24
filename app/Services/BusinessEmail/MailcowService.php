<?php

namespace App\Services\BusinessEmail;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Cliente HTTP para la API de Mailcow.
 *
 * Auth: Bearer token (X-API-Key header)
 * Docs: https://mailcow.github.io/mailcow-dockerized-docs/
 * API: https://<host>/api/v1/
 */
class MailcowService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('mailcow.base_url'), '/');
        $this->apiKey  = (string) config('mailcow.api_key', '');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dominios
    // ─────────────────────────────────────────────────────────────────────────

    public function listDomains(): array
    {
        return $this->get('/api/v1/get/domain/all');
    }

    public function domainExists(string $domain): bool
    {
        try {
            $domains = $this->listDomains();
            return collect($domains)->contains(fn ($d) => ($d['domain'] ?? '') === strtolower($domain));
        } catch (\Throwable) {
            return false;
        }
    }

    public function addDomain(string $domain, int $quotaMb = 0, int $maxMailboxes = 10): array
    {
        return $this->post('/api/v1/add/domain', [
            'domain'         => strtolower($domain),
            'description'    => 'Dominio de cliente ROKE Industries',
            'aliases'        => 400,
            'mailboxes'      => $maxMailboxes,
            'defquota'       => $quotaMb > 0 ? $quotaMb : (int) config('mailcow.default_quota_mb', 500),
            'maxquota'       => 0,       // 0 = sin límite global
            'quota'          => 0,
            'active'         => '1',
            'rl_frame'       => 's',
            'rl_value'       => '10',
        ]);
    }

    public function deleteDomain(string $domain): array
    {
        return $this->deleteBody('/api/v1/delete/domain', [strtolower($domain)]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Buzones (Mailboxes)
    // ─────────────────────────────────────────────────────────────────────────

    public function listMailboxes(string $domain): array
    {
        $result = $this->get('/api/v1/get/mailbox/all/' . rawurlencode(strtolower($domain)));

        if (!is_array($result)) {
            return [];
        }

        return array_map(fn (array $m) => $this->formatMailbox($m), $result);
    }

    public function createMailbox(string $localPart, string $domain, string $password, int $quotaMb = 0): array
    {
        $this->ensureDomainExists($domain);

        $result = $this->post('/api/v1/add/mailbox', [
            'local_part'           => strtolower($localPart),
            'domain'               => strtolower($domain),
            'password'             => $password,
            'password2'            => $password,
            'name'                 => $localPart . '@' . $domain,
            'quota'                => $quotaMb > 0 ? $quotaMb : (int) config('mailcow.default_quota_mb', 500),
            'active'               => '1',
            'force_pw_update'      => '0',
            'tls_enforce_in'       => '0',
            'tls_enforce_out'      => '0',
        ]);

        return $this->formatMailbox([
            'username' => strtolower($localPart) . '@' . strtolower($domain),
            'name'     => $localPart . '@' . $domain,
            'quota'    => ($quotaMb > 0 ? $quotaMb : (int) config('mailcow.default_quota_mb', 500)) * 1024 * 1024,
            'active'   => true,
        ]);
    }

    public function deleteMailbox(string $address): void
    {
        $this->deleteBody('/api/v1/delete/mailbox', [strtolower($address)]);
    }

    public function getMailbox(string $address): ?array
    {
        try {
            $result = $this->get('/api/v1/get/mailbox/' . rawurlencode(strtolower($address)));
            if (empty($result) || isset($result['type']) && $result['type'] === 'error') {
                return null;
            }
            if (isset($result[0])) {
                return $this->formatMailbox($result[0]);
            }
            return $this->formatMailbox($result);
        } catch (\Throwable) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Alias / Forwarders
    // ─────────────────────────────────────────────────────────────────────────

    public function listAliases(string $domain): array
    {
        $result = $this->get('/api/v1/get/alias/all/' . rawurlencode(strtolower($domain)));

        if (! is_array($result)) {
            return [];
        }

        return array_values(array_map(fn (array $a) => $this->formatAlias($a), $result));
    }

    public function createAlias(string $address, string $goto, bool $active = true): array
    {
        $result = $this->post('/api/v1/add/alias', [
            'address'      => strtolower($address),
            'goto'         => strtolower($goto),
            'active'       => $active ? '1' : '0',
            'sogo_visible' => '1',
        ]);

        // Mailcow returns [{"type":"success","log":["mailbox","add","alias",...],"msg":["alias_added"]}]
        // Fetch back the created alias to return its ID
        $aliases = $this->listAliases(substr(strrchr($address, '@'), 1));
        foreach ($aliases as $a) {
            if (strtolower($a['address']) === strtolower($address)) {
                return $a;
            }
        }

        return ['address' => $address, 'goto' => $goto, 'active' => $active];
    }

    public function deleteAlias(int $id): void
    {
        $this->deleteBody('/api/v1/delete/alias', [$id]);
    }

    private function formatAlias(array $raw): array
    {
        return [
            'id'         => (int) ($raw['id'] ?? 0),
            'address'    => $raw['address'] ?? '',
            'goto'       => $raw['goto']    ?? '',
            'active'     => (bool) ($raw['active'] ?? true),
            'created_at' => $raw['created'] ?? null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function ensureDomainExists(string $domain): void
    {
        if (! $this->domainExists($domain)) {
            $this->addDomain(
                $domain,
                (int) config('mailcow.default_quota_mb', 500),
                (int) config('mailcow.max_mailboxes_per_domain', 10)
            );
        }
    }

    private function formatMailbox(array $raw): array
    {
        $username = $raw['username'] ?? $raw['local_part'] . '@' . $raw['domain'] ?? '';
        $quotaBytes = (int) ($raw['quota'] ?? 0);

        return [
            'address'   => $username,
            'name'      => $raw['name'] ?? $username,
            'quota_mb'  => $quotaBytes > 0 ? (int) round($quotaBytes / 1024 / 1024) : 0,
            'used_mb'   => isset($raw['quota_used']) ? (int) round((int) $raw['quota_used'] / 1024 / 1024) : 0,
            'active'    => (bool) ($raw['active'] ?? true),
            'created_at'=> $raw['created'] ?? null,
        ];
    }

    private function get(string $path, array $query = []): mixed
    {
        $response = $this->http()->get($this->baseUrl . $path, $query);
        $this->assertOk($response, 'GET ' . $path);
        return $response->json();
    }

    private function post(string $path, array $body): mixed
    {
        $response = $this->http()->post($this->baseUrl . $path, $body);
        $this->assertOk($response, 'POST ' . $path);
        return $response->json();
    }

    private function deleteBody(string $path, array $body): mixed
    {
        $response = $this->http()->delete($this->baseUrl . $path, $body);
        $this->assertOk($response, 'DELETE ' . $path);
        return $response->json() ?? [];
    }

    private function http()
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('El servicio de correo no está configurado. Contacta a soporte.');
        }

        return Http::withHeaders(['X-API-Key' => $this->apiKey])
            ->timeout((int) config('mailcow.timeout', 15))
            ->acceptJson();
    }

    private function assertOk(Response $response, string $context): void
    {
        if ($response->failed()) {
            $body = $response->json();
            $detail = is_array($body) ? ($body['msg'] ?? $body['detail'] ?? $response->body()) : $response->body();

            throw new RuntimeException(
                "Mailcow [{$context}] HTTP {$response->status()}: {$detail}"
            );
        }

        $json = $response->json();

        // Mailcow devuelve errores como [{"type":"error","msg":"..."}]
        if (is_array($json) && isset($json[0]['type']) && $json[0]['type'] === 'error') {
            throw new RuntimeException('Mailcow error: ' . ($json[0]['msg'] ?? 'unknown'));
        }
    }
}
