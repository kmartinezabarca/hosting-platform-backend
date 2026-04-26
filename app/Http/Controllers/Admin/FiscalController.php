<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FiscalRegime;
use App\Models\CfdiUse;
use App\Models\CustomerFiscalProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FiscalController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Catálogos SAT — solo admins pueden activar/desactivar entradas
    // ─────────────────────────────────────────────────────────────────────────

    /** GET /admin/fiscal/regimes */
    public function regimes(Request $request): JsonResponse
    {
        $query = FiscalRegime::orderBy('code');

        if ($request->filled('active')) {
            $query->where('is_active', (bool) $request->get('active'));
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    /** PUT /admin/fiscal/regimes/{code}/toggle */
    public function toggleRegime(string $code): JsonResponse
    {
        $regime = FiscalRegime::where('code', $code)->firstOrFail();
        $regime->update(['is_active' => !$regime->is_active]);

        return response()->json([
            'success' => true,
            'message' => "Régimen {$code} " . ($regime->is_active ? 'activado' : 'desactivado') . '.',
            'data'    => $regime,
        ]);
    }

    /** GET /admin/fiscal/cfdi-uses */
    public function cfdiUses(Request $request): JsonResponse
    {
        $query = CfdiUse::orderBy('code');

        if ($request->filled('active')) {
            $query->where('is_active', (bool) $request->get('active'));
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    /** PUT /admin/fiscal/cfdi-uses/{code}/toggle */
    public function toggleCfdiUse(string $code): JsonResponse
    {
        $use = CfdiUse::where('code', $code)->firstOrFail();
        $use->update(['is_active' => !$use->is_active]);

        return response()->json([
            'success' => true,
            'message' => "Uso CFDI {$code} " . ($use->is_active ? 'activado' : 'desactivado') . '.',
            'data'    => $use,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Perfiles fiscales de clientes (solo lectura para admin)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/fiscal/profiles
     * Lista perfiles fiscales con filtros. Útil para soporte.
     */
    public function profiles(Request $request): JsonResponse
    {
        $query = CustomerFiscalProfile::with('user:id,uuid,first_name,last_name,email')
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->get('user_id')))
            ->when($request->filled('rfc'), fn($q) => $q->where('rfc', 'like', '%' . $request->get('rfc') . '%'))
            ->when($request->filled('regimen_fiscal'), fn($q) => $q->where('regimen_fiscal', $request->get('regimen_fiscal')))
            ->orderByDesc('created_at');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    /**
     * GET /admin/fiscal/profiles/{uuid}
     * Muestra un perfil fiscal específico.
     */
    public function showProfile(string $uuid): JsonResponse
    {
        $profile = CustomerFiscalProfile::with('user:id,uuid,first_name,last_name,email')
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $profile]);
    }
}
