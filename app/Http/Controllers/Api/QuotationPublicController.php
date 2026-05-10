<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuotationResource;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuotationPublicController extends Controller
{
    public function __construct(private readonly QuotationService $service) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/quotations/public/{token}
    // ─────────────────────────────────────────────────────────────────────────

    public function show(string $token): JsonResponse
    {
        $quotation = Quotation::where('public_token', $token)->first();

        if (!$quotation) {
            return response()->json([
                'success' => false,
                'message' => 'La cotización no fue encontrada o el enlace no es válido.',
            ], 404);
        }

        if ($quotation->isExpired()) {
            return response()->json([
                'success'    => false,
                'message'    => 'Este enlace ha expirado. Contacta al proveedor para obtener uno nuevo.',
                'expired_at' => $quotation->expires_at?->toIso8601String(),
            ], 410);
        }

        return response()->json([
            'success' => true,
            'data'    => new QuotationResource($quotation),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/quotations/public/{token}/viewed
    // ─────────────────────────────────────────────────────────────────────────

    public function markViewed(Request $request, string $token): JsonResponse
    {
        $quotation = Quotation::where('public_token', $token)->first();

        if (!$quotation) {
            return response()->json(['success' => false, 'message' => 'Cotización no encontrada.'], 404);
        }

        if ($quotation->isExpired()) {
            return response()->json(['success' => false, 'message' => 'El enlace ha expirado.'], 410);
        }

        try {
            $this->service->withRequest($request)->markViewed($quotation);
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
