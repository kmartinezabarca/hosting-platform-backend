<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Coolify\CoolifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class HostingController extends Controller
{
    public function __construct(private readonly CoolifyService $coolify) {}

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
