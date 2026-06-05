<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\Domain;
use App\Domains\Platform\Services\CloudflareService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Gestión de dominios del cliente.
 *
 * Arquitectura DNS: los dominios son propiedad del usuario en cualquier registrador
 * (Namecheap, GoDaddy, Porkbun, Hostinger, Cloudflare, etc.).
 * ROKE NO compra dominios automáticamente. El flujo es:
 *
 *   1. Cliente agrega su dominio → POST /domains
 *   2. Se verifica ownership via TXT record → POST /domains/{uuid}/verify-ownership
 *                                             POST /domains/{uuid}/confirm-ownership
 *   3. El cliente apunta sus nameservers a Cloudflare o configura un CNAME/A record
 *   4. DNS automático gestionado por Cloudflare API
 *   5. SSL automático vía Cloudflare Universal SSL / Let's Encrypt
 */
class DomainController extends Controller
{
    public function __construct(private readonly CloudflareService $cloudflare) {}

    private const ALLOWED_STATUSES = ['active', 'pending_transfer', 'expired', 'cancelled', 'suspended'];

    private const DOMAIN_REGEX = '/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i';

    /**
     * GET /client/domains
     *
     * Lista los dominios del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user    = Auth::user();
            $perPage = min((int) $request->get('per_page', 15), 100);
            $query   = Domain::where('user_id', $user->id);

            if ($request->filled('status') && in_array($request->status, self::ALLOWED_STATUSES, true)) {
                $query->where('status', $request->status);
            }

            $domains = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json(['success' => true, 'data' => $domains]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los dominios.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET /client/domains/{uuid}
     *
     * Detalle de un dominio específico.
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $user   = Auth::user();
            $domain = Domain::where('uuid', $uuid)
                            ->where('user_id', $user->id)
                            ->first();

            if (! $domain) {
                return response()->json(['success' => false, 'message' => 'Dominio no encontrado.'], 404);
            }

            return response()->json(['success' => true, 'data' => $domain]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el dominio.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST /client/domains
     *
     * Agrega un dominio externo al sistema.
     *
     * El dominio ya debe estar registrado en cualquier registrador (Namecheap,
     * GoDaddy, Porkbun, Cloudflare, etc.). ROKE no lo compra ni contacta al
     * registrador. El cliente es responsable de apuntar sus DNS.
     *
     * Body:
     *   domain_name         string   required  FQDN a importar (ej. "miempresa.com")
     *   registrar           string   optional  Registrador donde está registrado (solo referencia)
     *   expiration_years    int      optional  Años hasta vencimiento (default 1, max 10)
     *   auto_renew          bool     optional  Recordatorio de renovación
     *   whois_privacy       bool     optional  ¿Tiene privacy guard activo?
     *   nameservers         string[] optional  Nameservers actuales (informativo)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'domain_name'      => ['required', 'string', 'max:253', 'regex:' . self::DOMAIN_REGEX],
                'registrar'        => 'nullable|string|max:100',
                'expiration_years' => 'nullable|integer|min:1|max:10',
                'auto_renew'       => 'boolean',
                'whois_privacy'    => 'boolean',
                'nameservers'      => 'nullable|array|max:6',
                'nameservers.*'    => ['string', 'max:253', 'regex:' . self::DOMAIN_REGEX],
            ], [
                'domain_name.regex' => 'El nombre de dominio no tiene un formato válido.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos inválidos.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $user       = Auth::user();
            $domainName = strtolower($request->domain_name);

            // Verificar que el dominio no esté ya importado por este usuario
            $alreadyExists = Domain::where('user_id', $user->id)
                                   ->where('domain_name', $domainName)
                                   ->exists();

            if ($alreadyExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este dominio ya está agregado en tu cuenta.',
                ], 409);
            }

            $expirationYears = max(1, min(10, (int) $request->get('expiration_years', 1)));
            $expirationDate  = now()->addYears($expirationYears);

            $domain = Domain::create([
                'user_id'           => $user->id,
                'domain_name'       => $domainName,
                'registrar'         => $request->filled('registrar') ? $request->registrar : null,
                'registration_date' => now()->toDateString(),
                'expiration_date'   => $expirationDate->toDateString(),
                'auto_renew'        => $request->boolean('auto_renew', false),
                'whois_privacy'     => $request->boolean('whois_privacy', false),
                'nameservers'       => $request->get('nameservers', []),
                'status'            => 'active',
            ]);

            Log::info('Domain imported by client', [
                'domain'    => $domainName,
                'user_id'   => $user->id,
                'registrar' => $domain->registrar,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Dominio {$domainName} agregado correctamente. Verifica el ownership para activar todas las funciones.",
                'data'    => $domain,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al agregar el dominio.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * PUT /client/domains/{uuid}
     *
     * Actualiza configuración del dominio (auto_renew, privacy, nameservers, registrar).
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'auto_renew'    => 'boolean',
                'whois_privacy' => 'boolean',
                'registrar'     => 'nullable|string|max:100',
                'nameservers'   => 'nullable|array',
                'nameservers.*' => 'string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos inválidos.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $user   = Auth::user();
            $domain = Domain::where('uuid', $uuid)
                            ->where('user_id', $user->id)
                            ->first();

            if (! $domain) {
                return response()->json(['success' => false, 'message' => 'Dominio no encontrado.'], 404);
            }

            $updateData = [];
            if ($request->has('auto_renew'))    $updateData['auto_renew']    = $request->boolean('auto_renew');
            if ($request->has('whois_privacy')) $updateData['whois_privacy'] = $request->boolean('whois_privacy');
            if ($request->has('registrar'))     $updateData['registrar']     = $request->input('registrar');
            if ($request->has('nameservers'))   $updateData['nameservers']   = $request->input('nameservers');

            if (! empty($updateData)) {
                $domain->update($updateData);
            }

            return response()->json(['success' => true, 'message' => 'Dominio actualizado.', 'data' => $domain]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el dominio.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST /client/domains/{uuid}/renew
     *
     * Extiende la fecha de vencimiento registrada (no compra renovación al registrador).
     * El cliente debe renovar manualmente en su registrador.
     */
    public function renew(Request $request, string $uuid): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'renewal_period' => 'required|integer|min:1|max:10',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos inválidos.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $user   = Auth::user();
            $domain = Domain::where('uuid', $uuid)
                            ->where('user_id', $user->id)
                            ->first();

            if (! $domain) {
                return response()->json(['success' => false, 'message' => 'Dominio no encontrado.'], 404);
            }

            $base          = $domain->expiration_date->isFuture() ? $domain->expiration_date : now();
            $newExpiration = $base->addYears((int) $request->renewal_period);
            $domain->update(['expiration_date' => $newExpiration->toDateString()]);

            return response()->json([
                'success' => true,
                'message' => "Fecha de vencimiento actualizada hasta {$newExpiration->toDateString()}.",
                'data'    => $domain,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al renovar el dominio.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET /client/domains/stats
     *
     * Estadísticas de dominos del usuario.
     */
    public function getStats(): JsonResponse
    {
        try {
            $user = Auth::user();

            $stats = [
                'total'         => Domain::where('user_id', $user->id)->count(),
                'active'        => Domain::where('user_id', $user->id)->where('status', 'active')->count(),
                'expired'       => Domain::where('user_id', $user->id)->where('status', 'expired')->count(),
                'expiring_soon' => Domain::where('user_id', $user->id)
                                         ->where('status', 'active')
                                         ->where('expiration_date', '<=', now()->addDays(30))
                                         ->count(),
            ];

            return response()->json(['success' => true, 'data' => $stats]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ── Verificación de ownership ──────────────────────────────────────────────

    /**
     * POST /client/domains/{uuid}/verify-ownership
     *
     * Genera (o reutiliza) un token TXT para verificar que el cliente
     * controla el dominio. Funciona con cualquier registrador o panel DNS.
     *
     * Retorna instrucciones detalladas y el registro TXT a crear.
     */
    public function initOwnershipVerification(string $uuid): JsonResponse
    {
        $user   = Auth::user();
        $domain = Domain::where('uuid', $uuid)->where('user_id', $user->id)->firstOrFail();

        if ($domain->ownership_verified) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'already_verified' => true,
                    'verified_at'      => $domain->ownership_verified_at,
                ],
            ]);
        }

        // Generar token si no existe
        if (empty($domain->ownership_token)) {
            $domain->update(['ownership_token' => 'roke-verify=' . Str::random(40)]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'already_verified' => false,
                'instructions'     => [
                    'step1' => 'Accede al panel DNS de tu registrador o proveedor DNS (Cloudflare, GoDaddy, Porkbun, Hostinger, etc.).',
                    'step2' => 'Crea un registro TXT en el dominio raíz (@) con el valor indicado.',
                    'step3' => 'Espera la propagación DNS (generalmente 5–30 min; máx. 24 h) y haz clic en "Confirmar verificación".',
                    'tip'   => 'Puedes verificar la propagación en: https://toolbox.googleapps.com/apps/dig/#TXT/' . $domain->domain_name,
                ],
                'txt_record' => [
                    'type'  => 'TXT',
                    'name'  => '@',
                    'value' => $domain->ownership_token,
                    'ttl'   => 300,
                ],
                'domain'     => $domain->domain_name,
                'verify_url' => "/client/domains/{$domain->uuid}/confirm-ownership",
            ],
        ]);
    }

    /**
     * POST /client/domains/{uuid}/confirm-ownership
     *
     * Verifica que el registro TXT esté publicado consultando el DNS real.
     * Usa dns_get_record() — no caché, consulta directamente el DNS autoritativo.
     */
    public function confirmOwnershipVerification(string $uuid): JsonResponse
    {
        $user   = Auth::user();
        $domain = Domain::where('uuid', $uuid)->where('user_id', $user->id)->firstOrFail();

        if ($domain->ownership_verified) {
            return response()->json([
                'success' => true,
                'data'    => ['verified' => true, 'verified_at' => $domain->ownership_verified_at],
            ]);
        }

        if (empty($domain->ownership_token)) {
            return response()->json([
                'success' => false,
                'message' => 'Primero genera el token de verificación.',
            ], 422);
        }

        $verified = $this->checkTxtRecord($domain->domain_name, $domain->ownership_token);

        if (! $verified) {
            return response()->json([
                'success' => false,
                'message' => 'El registro TXT aún no está propagado. Puede tardar hasta 24 horas. Intenta de nuevo en unos minutos.',
                'data'    => [
                    'verified'       => false,
                    'expected_value' => $domain->ownership_token,
                    'check_url'      => 'https://toolbox.googleapps.com/apps/dig/#TXT/' . $domain->domain_name,
                ],
            ], 422);
        }

        $domain->update([
            'ownership_verified'    => true,
            'ownership_verified_at' => now(),
        ]);

        Log::info('Domain ownership verified', ['domain' => $domain->domain_name, 'user_id' => $user->id]);

        return response()->json([
            'success' => true,
            'message' => "¡Dominio {$domain->domain_name} verificado exitosamente!",
            'data'    => ['verified' => true, 'verified_at' => now()->toISOString()],
        ]);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Verifica que el valor TXT esperado exista en el DNS del dominio.
     * Consulta el DNS real (no caché local). Retorna true si se encontró.
     */
    private function checkTxtRecord(string $domainName, string $expectedValue): bool
    {
        try {
            $records = @dns_get_record($domainName, DNS_TXT);
            if (! is_array($records)) {
                return false;
            }

            foreach ($records as $record) {
                $txt = $record['txt'] ?? $record['entries'][0] ?? '';
                if (trim($txt) === trim($expectedValue)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // dns_get_record puede lanzar warnings — no es fatal
        }

        return false;
    }
}
