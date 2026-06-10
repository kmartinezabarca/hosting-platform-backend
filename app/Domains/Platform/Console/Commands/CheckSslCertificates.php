<?php

namespace App\Domains\Platform\Console\Commands;

use App\Domains\Platform\Models\SslCertificate;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Notifications\SslExpiryAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recorre todos los certificados SSL activos, verifica su estado real
 * mediante conexión TLS, actualiza la tabla ssl_certificates y notifica
 * al propietario si quedan ≤ 30 días para el vencimiento.
 *
 * Horario recomendado: diariamente a las 08:00
 *   → php artisan ssl:check-certificates
 */
class CheckSslCertificates extends Command
{
    protected $signature = 'ssl:check-certificates
                            {--service= : UUID del servicio (solo verifica ese)}
                            {--dry-run : No actualiza BD ni envía notificaciones}';

    protected $description = 'Verifica SSL de todos los servicios de hosting activos y envía alertas de vencimiento.';

    /** Días restantes que disparan una notificación. */
    private const ALERT_THRESHOLDS = [30, 15, 7];

    public function handle(): int
    {
        $dryRun     = $this->option('dry-run');
        $serviceUuid = $this->option('service');

        $query = Service::with(['plan', 'user'])
            ->where('status', 'active')
            ->whereHas('plan', fn ($q) => $q->where('provisioner', 'coolify'));

        if ($serviceUuid) {
            $query->where('uuid', $serviceUuid);
        }

        $services = $query->get();

        $this->info("Verificando SSL de {$services->count()} servicio(s)…");

        $checked    = 0;
        $alerts     = 0;
        $errors     = 0;

        foreach ($services as $service) {
            $conn   = $service->connection_details ?? [];
            $fqdn   = $conn['fqdn'] ?? $conn['domain'] ?? $service->domain ?? null;
            $domain = $fqdn ? preg_replace('#^https?://#', '', trim((string) $fqdn)) : null;
            $domain = $domain ? explode('/', $domain)[0] : null;

            if (! $domain) {
                continue;
            }

            $certInfo = $this->fetchCertInfo($domain);
            $checked++;

            if (isset($certInfo['error'])) {
                $this->warn("  {$domain}: {$certInfo['error']}");
                $errors++;
                continue;
            }

            // Buscar o crear el registro SSL
            /** @var SslCertificate $cert */
            $cert = SslCertificate::firstOrNew([
                'service_id' => $service->id,
                'domain'     => $domain,
            ]);

            $daysRemaining = $certInfo['days_remaining'] ?? null;

            if (! $dryRun) {
                $cert->fill([
                    'issuer'          => $certInfo['issuer'],
                    'type'            => $certInfo['is_self_signed'] ? 'self_signed' : 'cloudflare',
                    'valid_from'      => $certInfo['valid_from']  ? \Carbon\Carbon::parse($certInfo['valid_from'])  : null,
                    'valid_until'     => $certInfo['valid_to']    ? \Carbon\Carbon::parse($certInfo['valid_to'])    : null,
                    'last_checked_at' => now(),
                    'status'          => $this->resolveStatus($daysRemaining, $certInfo['is_self_signed'] ?? false),
                ])->save();
            }

            $this->line("  {$domain}: {$daysRemaining} días restantes [issuer: {$certInfo['issuer']}]");

            // Enviar alerta si aplica
            if ($daysRemaining !== null && $service->user) {
                foreach (self::ALERT_THRESHOLDS as $threshold) {
                    if ($daysRemaining > 0 && $daysRemaining <= $threshold) {
                        // Solo notificar si aún no se notificó para este threshold
                        $alreadyNotified = $cert->expiry_notified_at
                            && $cert->expiry_notified_at->isAfter(now()->subDays(1));

                        if (! $alreadyNotified) {
                            if ($dryRun) {
                                $this->line("    [DRY] Enviaría SslExpiryAlert a {$service->user->email} ({$threshold} días)");
                            } else {
                                try {
                                    $service->user->notify(new SslExpiryAlert($cert, $daysRemaining));
                                    $cert->update(['expiry_notified_at' => now()]);
                                    \App\Domains\Platform\Support\AdminNotifier::notify(
                                        'Certificado SSL por vencer',
                                        "El SSL de {$domain} (servicio '{$service->name}' de {$service->user->full_name}) vence en {$daysRemaining} días.",
                                        'admin_ssl_expiring',
                                        ['domain' => $domain, 'service_id' => $service->uuid ?? $service->id, 'days_remaining' => $daysRemaining],
                                    );
                                    $alerts++;
                                } catch (\Throwable $e) {
                                    Log::error('SslExpiryAlert falló', [
                                        'domain'  => $domain,
                                        'user_id' => $service->user_id,
                                        'error'   => $e->getMessage(),
                                    ]);
                                    $errors++;
                                }
                            }
                            break; // Solo la alerta más urgente por ciclo
                        }
                    }
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Verificados', 'Alertas enviadas', 'Errores'],
            [[$checked, $alerts, $errors]]
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resuelve el status del cert según días restantes.
     */
    private function resolveStatus(?int $days, bool $selfSigned): string
    {
        if ($selfSigned)     return 'active'; // no alertar por self-signed (Cloudflare los usa internamente)
        if ($days === null)  return 'pending';
        if ($days <= 0)      return 'expired';
        if ($days <= 30)     return 'expiring_soon';
        return 'active';
    }

    /**
     * Inspección TLS real via stream_socket.
     * Misma lógica que HostingController::fetchCertInfo().
     */
    private function fetchCertInfo(string $domain): array
    {
        $result = [
            'domain'         => $domain,
            'issuer'         => null,
            'valid_from'     => null,
            'valid_to'       => null,
            'days_remaining' => null,
            'is_valid'       => false,
            'is_self_signed' => false,
            'error'          => null,
        ];

        try {
            $ctx = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'SNI_enabled'       => true,
                    'peer_name'         => $domain,
                ],
            ]);

            $socket = @stream_socket_client(
                "ssl://{$domain}:443", $errno, $errstr, 8,
                STREAM_CLIENT_CONNECT, $ctx,
            );

            if (! $socket) {
                $result['error'] = $errstr ?: 'No se pudo conectar al puerto 443.';
                return $result;
            }

            $params = stream_context_get_params($socket);
            fclose($socket);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if (! $cert) {
                $result['error'] = 'Conexión OK pero sin certificado.';
                return $result;
            }

            $info        = openssl_x509_parse($cert);
            $validFromTs = (int) ($info['validFrom_time_t'] ?? 0);
            $validToTs   = (int) ($info['validTo_time_t']   ?? 0);
            $now         = time();
            $remaining   = $validToTs > 0 ? (int) ceil(($validToTs - $now) / 86400) : null;

            $result['issuer']         = $info['issuer']['O']  ?? $info['issuer']['CN']  ?? null;
            $result['valid_from']     = $validFromTs > 0 ? date('Y-m-d H:i:s', $validFromTs) : null;
            $result['valid_to']       = $validToTs   > 0 ? date('Y-m-d H:i:s', $validToTs)   : null;
            $result['days_remaining'] = $remaining;
            $result['is_valid']       = $remaining !== null && $remaining > 0;
            $result['is_self_signed'] = ! empty($info['subject'])
                && ! empty($info['issuer'])
                && $info['subject'] === $info['issuer'];
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}
