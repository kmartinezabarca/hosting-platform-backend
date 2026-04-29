<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use Illuminate\Http\JsonResponse;

class QuotationPublicController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/quotations/public/{token}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve la cotización si el token es válido y no ha expirado.
     *
     * 404 → token no existe
     * 410 → token expirado (Gone)
     * 200 → cotización completa
     */
    public function show(string $token): JsonResponse
    {
        try {
            $quotation = Quotation::where('public_token', $token)->first();

            if (!$quotation) {
                return response()->json([
                    'success' => false,
                    'message' => 'La cotización no fue encontrada o el enlace no es válido.',
                ], 404);
            }

            if ($quotation->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este enlace ha expirado. Contacta al proveedor para obtener uno nuevo.',
                    'expired_at' => $quotation->expires_at?->toIso8601String(),
                ], 410);
            }

            return response()->json([
                'success' => true,
                'data'    => $quotation,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la cotización.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/quotations/public/{token}/viewed
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Marca la cotización como vista. Idempotente: solo avanza de 'sent' → 'viewed'.
     */
    public function markViewed(string $token): JsonResponse
    {
        try {
            $quotation = Quotation::where('public_token', $token)->first();

            if (!$quotation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cotización no encontrada.',
                ], 404);
            }

            // Solo avanzamos si está en 'sent'; cualquier otro estado se respeta.
            if ($quotation->status === 'sent') {
                $quotation->update(['status' => 'viewed']);
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar la visita.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
