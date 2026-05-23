<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\BusinessEmail\MailcowService;
use App\Services\Coolify\CoolifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class HostingController extends Controller
{
    public function __construct(
        private readonly CoolifyService  $coolify,
        private readonly MailcowService  $mailcow,
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

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z0-9_]+$/'],
            'type' => ['sometimes', 'string', 'in:mariadb,mysql,postgresql'],
        ]);

        return $this->coolifyResponse(function () use ($service, $conn, $validated) {
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
                    'db_password'     => $db['_db_password'],
                    'db_type'         => $db['_db_type'],
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
        $target  = rawurldecode($dbUuid);

        return $this->coolifyResponse(function () use ($service, $conn, $target) {
            $this->coolify->deleteDatabase($target);

            // Limpiar connection_details si era la DB principal
            if (($conn['coolify_db_uuid'] ?? null) === $target) {
                $service->update([
                    'connection_details' => array_merge($conn, [
                        'coolify_db_uuid' => null,
                        'db_name'         => null,
                        'db_user'         => null,
                        'db_password'     => null,
                        'db_type'         => null,
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

        return response()->json([
            'success' => true,
            'data'    => [
                'access_type' => 'ftp_sftp',
                'message'     => 'El acceso a archivos se realiza mediante FTP o SFTP.',
                'ftp' => [
                    'host'     => $conn['ftp_host'] ?? $conn['fqdn'] ?? null,
                    'port'     => $conn['ftp_port'] ?? 21,
                    'username' => $conn['ftp_user'] ?? null,
                    'password' => $conn['ftp_password'] ?? null,
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

    // ── Correos empresariales ─────────────────────────────────────────────────

    /**
     * GET /hosting/{uuid}/emails
     * Lista los buzones de correo del dominio del servicio.
     */
    public function emails(string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $domain  = $this->emailDomain($service);

        if (! $domain) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'domain'    => null,
                    'mailboxes' => [],
                    'message'   => 'Agrega un dominio personalizado para activar los correos empresariales.',
                ],
            ]);
        }

        try {
            $mailboxes = $this->mailcow->listMailboxes($domain);

            return response()->json([
                'success' => true,
                'data'    => [
                    'domain'    => $domain,
                    'mailboxes' => $mailboxes,
                    'total'     => count($mailboxes),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('HostingController: error listando correos', [
                'service_id' => $service->id,
                'domain'     => $domain,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo obtener el listado de correos. Intenta de nuevo.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 502);
        }
    }

    /**
     * POST /hosting/{uuid}/emails
     * Crea un nuevo buzón de correo empresarial.
     *
     * Body: { local_part: "nombre", password: "...", quota_mb?: 500 }
     */
    public function createEmail(Request $request, string $uuid): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $domain  = $this->emailDomain($service);

        if (! $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Necesitas un dominio personalizado para crear correos empresariales.',
            ], 422);
        }

        $validated = $request->validate([
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._+-]+$/'],
            'password'   => ['required', 'string', 'min:8', 'max:128'],
            'quota_mb'   => ['sometimes', 'integer', 'min:100', 'max:10240'],
        ], [
            'local_part.regex' => 'La parte local del correo solo puede contener letras, números, puntos, guiones y guiones bajos.',
        ]);

        $address = strtolower($validated['local_part']) . '@' . $domain;

        try {
            $mailbox = $this->mailcow->createMailbox(
                $validated['local_part'],
                $domain,
                $validated['password'],
                (int) ($validated['quota_mb'] ?? config('mailcow.default_quota_mb', 500))
            );

            return response()->json([
                'success' => true,
                'message' => "Correo {$address} creado exitosamente.",
                'data'    => $mailbox,
            ], 201);
        } catch (\Throwable $e) {
            Log::warning('HostingController: error creando correo', [
                'service_id' => $service->id,
                'address'    => $address,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo crear el correo. Verifica que la dirección no exista ya.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }
    }

    /**
     * DELETE /hosting/{uuid}/emails/{account}
     * Elimina un buzón de correo empresarial.
     * {account} es la dirección completa codificada en URL (ej: info%40midominio.com).
     */
    public function deleteEmail(string $uuid, string $account): JsonResponse
    {
        $service = $this->hostingService($uuid);
        $domain  = $this->emailDomain($service);
        $address = rawurldecode($account);

        if (! $domain) {
            return response()->json([
                'success' => false,
                'message' => 'Servicio sin dominio de correo configurado.',
            ], 422);
        }

        // Validar que la dirección pertenece al dominio del servicio
        if (! str_ends_with(strtolower($address), '@' . strtolower($domain))) {
            return response()->json([
                'success' => false,
                'message' => 'La dirección de correo no pertenece al dominio de este servicio.',
            ], 403);
        }

        try {
            $this->mailcow->deleteMailbox($address);

            return response()->json([
                'success' => true,
                'message' => "Correo {$address} eliminado.",
            ]);
        } catch (\Throwable $e) {
            Log::warning('HostingController: error eliminando correo', [
                'service_id' => $service->id,
                'address'    => $address,
                'error'      => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo eliminar el correo. Intenta de nuevo.',
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
     * Resuelve el dominio del servicio para gestión de correos.
     * Prioridad: dominio personalizado > subdominio asignado.
     */
    private function emailDomain(Service $service): ?string
    {
        $conn = $service->connection_details ?? [];

        $domain = $service->domain ?? $conn['domain'] ?? null;
        if ($domain) {
            return strtolower(trim($domain));
        }

        // Solo se proveen correos en dominios personalizados, no en subdominios *.rokeindustries.com
        return null;
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
