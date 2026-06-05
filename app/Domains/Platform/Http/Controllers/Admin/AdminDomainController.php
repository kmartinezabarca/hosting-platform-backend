<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión administrativa de dominios.
 *
 * Expone:
 *   GET  /admin/domains               — lista paginada con filtros y stats
 *   POST /admin/domains/{uuid}/renew  — extiende la fecha de vencimiento
 */
class AdminDomainController extends Controller
{
    private const ALLOWED_STATUSES = ['active', 'pending_transfer', 'expired', 'cancelled', 'suspended'];
    private const ALLOWED_SORT     = ['expiration_date', 'created_at', 'domain_name', 'registration_date'];

    /**
     * GET /admin/domains
     *
     * Query params:
     *   search        string  busca en domain_name
     *   status        string  filtra por status
     *   user_id       int     filtra por cliente
     *   expiring_soon bool    solo dominios que vencen en ≤ 30 días
     *   sort_by       string  campo de orden (default: created_at)
     *   sort_dir      asc|desc
     *   per_page      int     1–100 (default 20)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min((int) $request->get('per_page', 20), 100);

            $query = Domain::with('user:id,uuid,first_name,last_name,email')
                           ->withCount(['dnsRecords', 'sslCertificates']);

            // ── Filtros ───────────────────────────────────────────────────────
            if ($request->filled('search')) {
                $query->where('domain_name', 'like', '%' . $request->search . '%');
            }

            if ($request->filled('status') && in_array($request->status, self::ALLOWED_STATUSES, true)) {
                $query->where('status', $request->status);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->user_id);
            }

            if ($request->boolean('expiring_soon')) {
                $query->where('status', 'active')
                      ->where('expiration_date', '<=', now()->addDays(30))
                      ->where('expiration_date', '>=', now());
            }

            // ── Orden ─────────────────────────────────────────────────────────
            $sortBy  = in_array($request->get('sort_by'), self::ALLOWED_SORT, true)
                ? $request->sort_by
                : 'created_at';
            $sortDir = $request->get('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';

            $domains = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

            // ── Stats de resumen ──────────────────────────────────────────────
            $stats = [
                'total'         => Domain::count(),
                'active'        => Domain::where('status', 'active')->count(),
                'expired'       => Domain::where('status', 'expired')->count(),
                'expiring_soon' => Domain::where('status', 'active')
                                         ->where('expiration_date', '<=', now()->addDays(30))
                                         ->where('expiration_date', '>=', now())
                                         ->count(),
            ];

            return response()->json([
                'success' => true,
                'data'    => $domains,
                'stats'   => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los dominios.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * POST /admin/domains/{uuid}/renew
     *
     * Body: { years: int (1–10) }
     * Extiende la fecha de vencimiento manualmente desde el panel de admin.
     */
    public function renew(Request $request, string $uuid): JsonResponse
    {
        try {
            $years  = max(1, min(10, (int) $request->get('years', 1)));
            $domain = Domain::where('uuid', $uuid)->firstOrFail();

            $base       = $domain->expiration_date?->isFuture() ? $domain->expiration_date : now();
            $newExpiry  = $base->addYears($years);

            $domain->update([
                'expiration_date' => $newExpiry->toDateString(),
                'status'          => 'active',
            ]);

            return response()->json([
                'success' => true,
                'message' => "Dominio {$domain->domain_name} renovado por {$years} año(s).",
                'data'    => $domain->fresh(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Dominio no encontrado.'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al renovar el dominio.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
