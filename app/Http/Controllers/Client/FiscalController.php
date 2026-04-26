<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\CustomerFiscalProfile;
use App\Models\FiscalRegime;
use App\Models\CfdiUse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FiscalController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Catálogos SAT (públicos dentro de auth)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/fiscal/regimes
     * Devuelve todos los regímenes fiscales activos del SAT.
     * Acepta ?type=fisica|moral para filtrar.
     */
    public function regimes(Request $request): JsonResponse
    {
        $query = FiscalRegime::active()->orderBy('code');

        if ($request->get('type') === 'fisica') {
            $query->forFisica();
        } elseif ($request->get('type') === 'moral') {
            $query->forMoral();
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(['code', 'description', 'applies_to_fisica', 'applies_to_moral']),
        ]);
    }

    /**
     * GET /api/fiscal/cfdi-uses
     * Devuelve todos los usos de CFDI activos del SAT.
     * Acepta ?type=fisica|moral para filtrar.
     */
    public function cfdiUses(Request $request): JsonResponse
    {
        $query = CfdiUse::active()->orderBy('code');

        if ($request->get('type') === 'fisica') {
            $query->forFisica();
        } elseif ($request->get('type') === 'moral') {
            $query->forMoral();
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(['code', 'description', 'applies_to_fisica', 'applies_to_moral']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Perfiles fiscales del cliente
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/fiscal/profiles
     * Lista todos los perfiles fiscales guardados del usuario autenticado.
     */
    public function index(): JsonResponse
    {
        $profiles = CustomerFiscalProfile::where('user_id', Auth::id())
            ->orderByDesc('is_default')
            ->orderBy('alias')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $profiles,
        ]);
    }

    /**
     * POST /api/fiscal/profiles
     * Crea un nuevo perfil fiscal para el usuario.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'alias'          => ['nullable', 'string', 'max:100'],
            'rfc'            => ['required', 'string', 'min:12', 'max:13', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/i'],
            'razon_social'   => ['required', 'string', 'max:255'],
            'codigo_postal'  => ['required', 'string', 'digits:5'],
            'regimen_fiscal' => ['required', 'string', 'exists:fiscal_regimes,code'],
            'uso_cfdi'       => ['required', 'string', 'exists:cfdi_uses,code'],
            'is_default'     => ['sometimes', 'boolean'],
        ], [
            'rfc.regex'              => 'El RFC no tiene un formato válido.',
            'regimen_fiscal.exists'  => 'El régimen fiscal no es válido.',
            'uso_cfdi.exists'        => 'El uso de CFDI no es válido.',
        ]);

        $user = Auth::user();

        // Si es el primero del usuario, forzamos is_default = true
        $isFirst = CustomerFiscalProfile::where('user_id', $user->id)->doesntExist();

        $profile = CustomerFiscalProfile::create(array_merge($validated, [
            'user_id'    => $user->id,
            'is_default' => $isFirst || ($validated['is_default'] ?? false),
        ]));

        // Si se marcó como default, quitar el flag de los demás
        if ($profile->is_default) {
            CustomerFiscalProfile::where('user_id', $user->id)
                ->where('id', '!=', $profile->id)
                ->update(['is_default' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Perfil fiscal guardado.',
            'data'    => $profile,
        ], 201);
    }

    /**
     * GET /api/fiscal/profiles/{uuid}
     * Muestra un perfil fiscal específico del usuario.
     */
    public function show(string $uuid): JsonResponse
    {
        $profile = CustomerFiscalProfile::where('uuid', $uuid)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => $profile,
        ]);
    }

    /**
     * PUT /api/fiscal/profiles/{uuid}
     * Actualiza un perfil fiscal del usuario.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $profile = CustomerFiscalProfile::where('uuid', $uuid)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $validated = $request->validate([
            'alias'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'rfc'            => ['sometimes', 'string', 'min:12', 'max:13', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/i'],
            'razon_social'   => ['sometimes', 'string', 'max:255'],
            'codigo_postal'  => ['sometimes', 'string', 'digits:5'],
            'regimen_fiscal' => ['sometimes', 'string', 'exists:fiscal_regimes,code'],
            'uso_cfdi'       => ['sometimes', 'string', 'exists:cfdi_uses,code'],
            'is_default'     => ['sometimes', 'boolean'],
        ], [
            'rfc.regex'             => 'El RFC no tiene un formato válido.',
            'regimen_fiscal.exists' => 'El régimen fiscal no es válido.',
            'uso_cfdi.exists'       => 'El uso de CFDI no es válido.',
        ]);

        $profile->update($validated);

        // Si se activó como default, desactivar los demás
        if (!empty($validated['is_default']) && $validated['is_default']) {
            CustomerFiscalProfile::where('user_id', Auth::id())
                ->where('id', '!=', $profile->id)
                ->update(['is_default' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Perfil fiscal actualizado.',
            'data'    => $profile->fresh(),
        ]);
    }

    /**
     * DELETE /api/fiscal/profiles/{uuid}
     * Elimina (soft delete) un perfil fiscal del usuario.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $profile = CustomerFiscalProfile::where('uuid', $uuid)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $wasDefault = $profile->is_default;
        $userId     = $profile->user_id;

        $profile->delete();

        // Si era el default, promover el más reciente como nuevo default
        if ($wasDefault) {
            $next = CustomerFiscalProfile::where('user_id', $userId)->latest()->first();
            $next?->update(['is_default' => true]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Perfil fiscal eliminado.',
        ]);
    }

    /**
     * PUT /api/fiscal/profiles/{uuid}/set-default
     * Marca el perfil como predeterminado.
     */
    public function setDefault(string $uuid): JsonResponse
    {
        $profile = CustomerFiscalProfile::where('uuid', $uuid)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $profile->setAsDefault();

        return response()->json([
            'success' => true,
            'message' => 'Perfil fiscal establecido como predeterminado.',
            'data'    => $profile->fresh(),
        ]);
    }
}
