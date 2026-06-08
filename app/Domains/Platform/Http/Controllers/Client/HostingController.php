<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Services\CloudflareService;
use App\Domains\Platform\Services\Coolify\CoolifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class HostingController extends Controller
{
    public function __construct(
        private readonly CoolifyService    $coolify,
        private readonly CloudflareService $cloudflare,
    ) {}

    public function info(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $appUuid = $this->appUuidOrNull($service);

        if (!$appUuid) {
            return response()->json([
                'success' => true,
                'data' => [
                    'service'            => $service,
                    'connection_details' => $this->safeConnectionDetails($service),
                    'coolify_app'        => null,
                    'coolify_db'         => null,
                    'provisioning' => [
                        'status'  => 'pending',
                        'message' => 'El servicio existe, pero todavía no ha sido aprovisionado en Coolify.',
                    ],
                ],
            ]);
        }

        return $this->coolifyResponse(function () use ($service, $appUuid) {
            $conn    = $service->connection_details ?? [];
            $app     = $this->coolify->getApplication($appUuid);
            $db      = null;

            if (!empty($conn['coolify_db_uuid'])) {
                try {
                    $db = $this->coolify->getDatabase($conn['coolify_db_uuid']);
                } catch (\Throwable) {
                    // no fatal
                }
            }

            return [
                'service'            => $service,
                'connection_details' => $this->safeConnectionDetails($service),
                'coolify_app'        => $app,
                'coolify_db'         => $db,
            ];
        });
    }

    public function databases(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $conn    = $service->connection_details ?? [];
        $dbUuid  = $conn['coolify_db_uuid'] ?? null;

        if (!$dbUuid) {
            return response()->json([
                'success' => true,
                'data'    => ['databases' => []],
            ]);
        }

        return $this->coolifyResponse(fn () => [
            'databases' => [$this->coolify->getDatabase($dbUuid)],
        ]);
    }

    public function createDatabase(Request $request, string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $conn    = $service->connection_details ?? [];
        $secrets = $service->connection_secrets ?? [];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z0-9_]+$/'],
            'type' => ['sometimes', 'string', 'in:mariadb,mysql,postgresql'],
        ]);

        return $this->coolifyResponse(function () use ($service, $conn, $secrets, $validated) {
            $db = $this->coolify->createDatabase([
                'project_uuid' => $conn['coolify_project_uuid'],
                'server_uuid'  => config('coolify.server_uuid'),
                'name'         => strtolower($validated['name']),
                'type'         => $validated['type'] ?? 'mariadb',
            ]);

            // Registrar el nuevo UUID en connection_details
            $service->update([
                'connection_details' => array_merge($conn, [
                    'coolify_db_uuid' => $db['uuid'],
                    'db_name'         => $db['_db_name'],
                    'db_user'         => $db['_db_user'],
                    'db_type'         => $db['_db_type'],
                ]),
                'connection_secrets' => array_merge($secrets, [
                    'db_password' => $db['_db_password'],
                ]),
            ]);

            return [
                'database'    => $db,
                'db_name'     => $db['_db_name'],
                'db_user'     => $db['_db_user'],
                'db_password' => $db['_db_password'],
            ];
        }, 201);
    }

    public function deleteDatabase(string $uuid, string $dbUuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $conn    = $service->connection_details ?? [];
        $secrets = $service->connection_secrets ?? [];
        $target  = rawurldecode($dbUuid);

        return $this->coolifyResponse(function () use ($service, $conn, $secrets, $target) {
            $this->coolify->deleteDatabase($target);

            // Limpiar connection_details si era la DB principal
            if (($conn['coolify_db_uuid'] ?? null) === $target) {
                $service->update([
                    'connection_details' => array_merge($conn, [
                        'coolify_db_uuid' => null,
                        'db_name'         => null,
                        'db_user'         => null,
                        'db_type'         => null,
                    ]),
                    'connection_secrets' => array_merge($secrets, [
                        'db_password' => null,
                    ]),
                ]);
            }

            return ['deleted' => true];
        });
    }

    public function domains(string $uuid): JsonResponse
    {
        $service     = $this->hostingService($uuid);
        $projectUuid = $this->requireProjectUuid($service);

        return $this->coolifyResponse(fn () => [
            'domains' => $this->coolify->listApplications($projectUuid),
        ]);
    }

    public function createDomain(Request $request, string $uuid): JsonResponse
    {
        $service     = $this->hostingService($uuid);
        $conn        = $service->connection_details ?? [];
        $projectUuid = $this->requireProjectUuid($service);

        $validated = $request->validate([
            'domain'     => ['required', 'string', 'max:255'],
            'build_pack' => ['sometimes', 'string', 'in:static,php'],
        ]);

        $domain = $this->normalizeDomain($validated['domain']);
        $fqdn   = "https://{$domain}";

        return $this->coolifyResponse(fn () => [
            'application' => $this->coolify->createApplication([
                'project_uuid' => $projectUuid,
                'server_uuid'  => config('coolify.server_uuid'),
                'name'         => $domain,
                'build_pack'   => $validated['build_pack'] ?? 'static',
                'fqdn'         => $fqdn,
            ]),
        ], 201);
    }

    public function deleteDomain(string $uuid, string $appUuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $target  = rawurldecode($appUuid);

        return $this->coolifyResponse(function () use ($service, $target) {
            $conn = $service->connection_details ?? [];

            $this->coolify->deleteApplication($target);

            // Si era la app principal, limpiar el UUID
            if (($conn['coolify_app_uuid'] ?? null) === $target) {
                $service->update([
                    'connection_details' => array_merge($conn, [
                        'coolify_app_uuid' => null,
                        'fqdn'             => null,
                    ]),
                    'status' => 'suspended',
                ]);
            }

            return ['deleted' => true];
        });
    }

    public function stats(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $appUuid = $this->requireAppUuid($service);

        return $this->coolifyResponse(fn () => [
            'stats' => $this->coolify->getApplication($appUuid),
        ]);
    }

    // ── DNS ───────────────────────────────────────────────────────────────────

    /**
     * GET /hosting/{uuid}/dns
     * Lista los registros DNS de Cloudflare del subdominio/dominio del servicio.
     */
    public function dnsRecords(string $uuid): JsonResponse
    {
        $service   = $this->hostingService($uuid);
        $prefix    = $this->dnsPrefix($service);

        if (! $prefix) {
            return response()->json([
                'success' => true,
                'data'    => ['records' => [], 'prefix' => null,
                              'message' => 'El servicio no tiene subdominio o dominio configurado.'],
            ]);
        }

        try {
            $records = $this->cloudflare->listRecordsByPrefix($prefix);
            return response()->json([
                'success' => true,
                'data'    => ['records' => $records, 'prefix' => $prefix],
            ]);
        } catch (\Throwable $e) {
            Log::warning('DNS: no se pudieron listar registros', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudieron obtener los registros DNS. Intenta de nuevo.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 502);
        }
    }

    /**
     * POST /hosting/{uuid}/dns
     * Crea un nuevo registro DNS en Cloudflare.
     * Body: { type, name, content, ttl?, proxied?, priority? }
     */
    public function createDnsRecord(Request $request, string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $prefix  = $this->dnsPrefix($service);

        $validated = $request->validate([
            'type'     => ['required', 'string', \Illuminate\Validation\Rule::in(['A','AAAA','CNAME','MX','TXT','NS','SRV'])],
            'name'     => ['required', 'string', 'max:253'],
            'content'  => ['required', 'string', 'max:1024'],
            'ttl'      => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'proxied'  => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        // Rechazar nombres que no pertenezcan al subdominio del servicio
        $name = strtolower(trim($validated['name']));
        if ($prefix && ! str_starts_with($name, strtolower($prefix))) {
            return response()->json([
                'success' => false,
                'message' => "El nombre del registro debe pertenecer al dominio «{$prefix}».",
            ], 422);
        }

        $extra = [];
        if (isset($validated['priority'])) {
            $extra['priority'] = (int) $validated['priority'];
        }

        try {
            $record = $this->cloudflare->createRecord(
                $validated['type'],
                $name,
                $validated['content'],
                (int) ($validated['ttl']    ?? 3600),
                (bool)($validated['proxied'] ?? false),
                $extra,
            );
            return response()->json(['success' => true, 'data' => $record], 201);
        } catch (\Throwable $e) {
            Log::warning('DNS: no se pudo crear registro', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo crear el registro. ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * PUT /hosting/{uuid}/dns/{recordId}
     * Actualiza un registro DNS existente.
     */
    public function updateDnsRecord(Request $request, string $uuid, string $recordId): JsonResponse
    {
        $this->hostingService($uuid); // authorize ownership

        $validated = $request->validate([
            'content'  => ['required', 'string', 'max:1024'],
            'ttl'      => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'proxied'  => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        try {
            $record = $this->cloudflare->updateRecord($recordId, $validated);
            return response()->json(['success' => true, 'data' => $record]);
        } catch (\Throwable $e) {
            Log::warning('DNS: no se pudo actualizar registro', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo actualizar el registro. ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * DELETE /hosting/{uuid}/dns/{recordId}
     * Elimina un registro DNS.
     */
    public function deleteDnsRecord(string $uuid, string $recordId): JsonResponse
    {
        $this->hostingService($uuid);

        try {
            $this->cloudflare->deleteRecord($recordId);
            return response()->json(['success' => true, 'message' => 'Registro DNS eliminado.']);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo eliminar el registro.',
            ], 502);
        }
    }

    /** Resuelve el prefijo DNS del servicio (subdominio.base o dominio custom). */
    private function dnsPrefix(Service $service): ?string
    {
        $conn = $service->connection_details ?? [];
        // Custom domain takes priority
        if (! empty($conn['domain'])) {
            return strtolower(trim($conn['domain']));
        }
        // Subdomain on ROKE zone
        if (! empty($conn['subdomain'])) {
            return strtolower(trim($conn['subdomain']));
        }
        // Parse from fqdn
        $fqdn = $conn['fqdn'] ?? null;
        if ($fqdn) {
            $host = preg_replace('#^https?://#', '', $fqdn);
            return strtolower(explode('/', $host)[0]);
        }
        return null;
    }

    // ── SSL ───────────────────────────────────────────────────────────────────

    /**
     * GET /hosting/{uuid}/ssl
     * Devuelve el estado del certificado TLS y si se fuerza HTTPS.
     */
    public function ssl(string $uuid): JsonResponse
    {
        $service    = $this->hostingService($uuid);
        $appUuid    = $this->appUuidOrNull($service);
        $forceHttps = false;

        if ($appUuid) {
            try {
                $app        = $this->coolify->getApplication($appUuid);
                $forceHttps = (bool) ($app['redirect_http_to_https'] ?? false);
            } catch (\Throwable $e) {
                Log::warning('SSL: no se pudo obtener app de Coolify', ['error' => $e->getMessage()]);
            }
        }

        // Resolver dominio para inspeccionar cert
        $conn   = $service->connection_details ?? [];
        $fqdn   = $conn['fqdn'] ?? $conn['domain'] ?? $service->domain ?? null;
        $domain = $fqdn ? preg_replace('#^https?://#', '', trim((string) $fqdn)) : null;
        $domain = $domain ? explode('/', $domain)[0] : null;

        $cert = $domain ? $this->fetchCertInfo($domain) : null;

        return response()->json([
            'success' => true,
            'data'    => [
                'force_https' => $forceHttps,
                'certificate' => $cert,
            ],
        ]);
    }

    /**
     * POST /hosting/{uuid}/ssl/toggle-https
     * Activa o desactiva la redirección forzada HTTP → HTTPS en Coolify.
     */
    public function toggleForceHttps(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $appUuid = $this->requireAppUuid($service);

        return $this->coolifyResponse(function () use ($appUuid) {
            $app      = $this->coolify->getApplication($appUuid);
            $current  = (bool) ($app['redirect_http_to_https'] ?? false);
            $newValue = ! $current;

            $this->coolify->updateApplication($appUuid, [
                'redirect_http_to_https' => $newValue,
            ]);

            return ['force_https' => $newValue];
        });
    }

    // ── Administrador de archivos ─────────────────────────────────────────────

    /**
     * GET /hosting/{uuid}/files
     * Retorna las credenciales de acceso FTP/SFTP para gestionar archivos.
     * Coolify no expone una API de gestor de archivos; el acceso es vía FTP/SFTP
     * con las credenciales almacenadas en connection_details.
     */
    public function files(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $conn    = $service->connection_details ?? [];
        $secrets = $service->connection_secrets ?? [];

        return response()->json([
            'success' => true,
            'data'    => [
                'access_type' => 'ftp_sftp',
                'message'     => 'El acceso a archivos se realiza mediante FTP o SFTP.',
                'ftp' => [
                    'host'     => $conn['ftp_host'] ?? $conn['fqdn'] ?? null,
                    'port'     => $conn['ftp_port'] ?? 21,
                    'username' => $conn['ftp_user'] ?? null,
                    'password' => $secrets['ftp_password'] ?? $conn['ftp_password'] ?? null,
                ],
                'sftp' => [
                    'host'     => $conn['sftp_host'] ?? $conn['fqdn'] ?? null,
                    'port'     => $conn['sftp_port'] ?? 22,
                    'username' => $conn['sftp_user'] ?? null,
                ],
                'panel_url' => config('coolify.base_url'),
                'note'      => 'Puedes gestionar tus archivos desde el panel de Coolify o mediante un cliente FTP como FileZilla.',
            ],
        ]);
    }

    // ── WordPress manager ─────────────────────────────────────────────────────

    /**
     * GET /hosting/{uuid}/wordpress
     * Prueba el endpoint /wp-json/ del sitio y devuelve metadatos de WordPress.
     * También realiza comprobaciones de salud básicas.
     */
    public function wordpress(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $conn    = $service->connection_details ?? [];
        $fqdn    = $conn['fqdn'] ?? $service->coolify_app['fqdn'] ?? null;
        $domain  = $conn['domain'] ?? ($fqdn ? preg_replace('#^https?://#', '', rtrim($fqdn, '/')) : null);

        if (! $domain && ! $fqdn) {
            return response()->json([
                'success' => true,
                'data'    => ['detected' => false, 'message' => 'El servicio no tiene una URL configurada.'],
            ]);
        }

        $siteUrl  = $fqdn ?? 'https://' . $domain;
        $wpApiUrl = rtrim($siteUrl, '/') . '/wp-json/';

        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(8)
                ->withoutVerifying()
                ->get($wpApiUrl);

            if (! $resp->ok()) {
                return response()->json([
                    'success' => true,
                    'data'    => [
                        'detected'    => false,
                        'site_url'    => $siteUrl,
                        'admin_url'   => rtrim($siteUrl, '/') . '/wp-admin/',
                        'message'     => 'No se detectó una instalación de WordPress en este sitio.',
                        'http_status' => $resp->status(),
                    ],
                ]);
            }

            $json    = $resp->json() ?? [];
            $wpVer   = $json['generator'] ?? null;
            // Parse version from "https://wordpress.org/?v=6.5.3"
            if ($wpVer && preg_match('/v=([\d.]+)/', $wpVer, $m)) {
                $wpVer = $m[1];
            }

            // Detect SSL
            $sslOk = str_starts_with($siteUrl, 'https://');

            return response()->json([
                'success' => true,
                'data'    => [
                    'detected'       => true,
                    'site_url'       => $siteUrl,
                    'admin_url'      => rtrim($siteUrl, '/') . '/wp-admin/',
                    'site_name'      => $json['name']        ?? null,
                    'site_tagline'   => $json['description'] ?? null,
                    'wp_version'     => $wpVer,
                    'timezone'       => $json['timezone']    ?? null,
                    'language'       => $json['language']    ?? null,
                    'api_accessible' => true,
                    'ssl_enabled'    => $sslOk,
                    'namespaces'     => $json['namespaces']  ?? [],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'detected'    => false,
                    'site_url'    => $siteUrl,
                    'admin_url'   => rtrim($siteUrl, '/') . '/wp-admin/',
                    'message'     => 'No se pudo conectar al sitio.',
                    'api_accessible' => false,
                ],
            ]);
        }
    }

    /**
     * POST /hosting/{uuid}/wordpress/restart
     * Reinicia el contenedor de la aplicación en Coolify (limpia cachés en memoria).
     */
    public function wordpressRestart(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $appUuid = $this->requireAppUuid($service);

        try {
            $this->coolify->restartApplication($appUuid);
            return response()->json(['success' => true, 'message' => 'Contenedor reiniciado. Las cachés en memoria han sido eliminadas.']);
        } catch (\Throwable $e) {
            Log::warning('wordpress restart failed', ['service_id' => $service->id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo reiniciar el contenedor.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 502);
        }
    }

    /**
     * POST /hosting/{uuid}/wordpress/deploy
     * Dispara un nuevo despliegue desde el repositorio fuente en Coolify.
     */
    public function wordpressDeploy(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $appUuid = $this->requireAppUuid($service);

        try {
            $result = $this->coolify->deployApplication($appUuid);
            return response()->json([
                'success' => true,
                'message' => 'Redespliegue iniciado. El sitio se actualizará en unos minutos.',
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            Log::warning('wordpress deploy failed', ['service_id' => $service->id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo iniciar el redespliegue.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 502);
        }
    }

    /**
     * POST /hosting/{uuid}/restart
     * Reinicia el contenedor de la aplicación en Coolify (cualquier hosting,
     * no solo WordPress). Limpia cachés en memoria sin perder datos.
     */
    public function restart(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $appUuid = $this->requireAppUuid($service);

        try {
            $this->coolify->restartApplication($appUuid);
            return response()->json(['success' => true, 'message' => 'Servicio reiniciado correctamente.']);
        } catch (\Throwable $e) {
            Log::warning('hosting restart failed', ['service_id' => $service->id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo reiniciar el servicio.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 502);
        }
    }

    /**
     * POST /hosting/{uuid}/redeploy
     * Dispara un nuevo despliegue desde el origen en Coolify (cualquier hosting).
     */
    public function redeploy(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $appUuid = $this->requireAppUuid($service);

        try {
            $result = $this->coolify->deployApplication($appUuid);
            return response()->json([
                'success' => true,
                'message' => 'Redespliegue iniciado. El servicio se actualizará en unos minutos.',
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            Log::warning('hosting redeploy failed', ['service_id' => $service->id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo iniciar el redespliegue.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 502);
        }
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function hostingService(string $uuid): Service
    {
        $service = Service::where('uuid', $uuid)
            ->where('user_id', Auth::id())
            ->with('plan')
            ->firstOrFail();

        if ($service->plan?->provisioner !== 'coolify') {
            abort(404, 'Este servicio no es un Web Hosting administrado por Coolify.');
        }

        return $service;
    }

    private function requireAppUuid(Service $service): string
    {
        $uuid = $this->appUuidOrNull($service);

        if (!$uuid) {
            abort(409, 'El servicio de hosting todavía no ha sido aprovisionado en Coolify.');
        }

        return $uuid;
    }

    private function requireProjectUuid(Service $service): string
    {
        $uuid = $service->connection_details['coolify_project_uuid'] ?? null;

        if (!$uuid) {
            abort(409, 'El servicio de hosting todavía no ha sido aprovisionado en Coolify.');
        }

        return $uuid;
    }

    private function appUuidOrNull(Service $service): ?string
    {
        return $service->connection_details['coolify_app_uuid'] ?? $service->external_id;
    }

    private function normalizeDomain(?string $domain): string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = trim(explode('/', $domain)[0] ?? '', '.');

        if ($domain === '') {
            abort(422, 'Debes indicar un dominio.');
        }

        return $domain;
    }

    private function safeConnectionDetails(Service $service): array
    {
        $details = $service->connection_details ?? [];
        unset($details['db_password']);

        return $details;
    }

    /**
     * Realiza un handshake TLS con el dominio y extrae información del certificado.
     * No lanza excepciones — siempre retorna un array con 'error' si algo falla.
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
                    'verify_peer'       => false,   // capturar incluso auto-firmados
                    'verify_peer_name'  => false,
                    'SNI_enabled'       => true,
                    'peer_name'         => $domain,
                ],
            ]);

            $socket = @stream_socket_client(
                "ssl://{$domain}:443",
                $errno, $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $ctx,
            );

            if (! $socket) {
                $result['error'] = $errstr ?: 'No se pudo conectar al dominio en el puerto 443.';
                return $result;
            }

            $params = stream_context_get_params($socket);
            fclose($socket);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if (! $cert) {
                $result['error'] = 'Conexión establecida pero no se obtuvo el certificado.';
                return $result;
            }

            $info = openssl_x509_parse($cert);

            $validFromTs = (int) ($info['validFrom_time_t'] ?? 0);
            $validToTs   = (int) ($info['validTo_time_t']   ?? 0);
            $now         = time();
            $remaining   = $validToTs > 0 ? (int) ceil(($validToTs - $now) / 86400) : null;

            $issuerOrg  = $info['issuer']['O']  ?? $info['issuer']['CN']  ?? null;
            $isSelfSigned = ! empty($info['subject']) && ! empty($info['issuer'])
                && ($info['subject'] === $info['issuer']);

            $result['issuer']         = $issuerOrg;
            $result['valid_from']     = $validFromTs > 0 ? date('Y-m-d H:i:s', $validFromTs) : null;
            $result['valid_to']       = $validToTs   > 0 ? date('Y-m-d H:i:s', $validToTs)   : null;
            $result['days_remaining'] = $remaining;
            $result['is_valid']       = $remaining !== null && $remaining > 0;
            $result['is_self_signed'] = $isSelfSigned;
        } catch (\Throwable $e) {
            $result['error'] = 'Error al inspeccionar el certificado: ' . $e->getMessage();
        }

        return $result;
    }

    private function coolifyResponse(callable $callback, int $status = 200): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $callback(),
            ], $status);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Error en HostingController/Coolify', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar con el panel de hosting. Intenta de nuevo.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 502);
        }
    }
}
