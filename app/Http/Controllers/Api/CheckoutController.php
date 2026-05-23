<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CheckoutQuoteException;
use App\Http\Controllers\Controller;
use App\Services\CheckoutQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutQuoteService $quotes)
    {
    }

    public function catalog(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->quotes->catalog(),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id'             => ['required'],
            'billing_cycle'       => ['required', 'string'],
            'add_ons'             => ['sometimes', 'array'],
            'add_ons.*'           => ['distinct'],
            'generate_cfdi'       => ['sometimes', 'boolean'],
            'fiscal_profile_uuid' => ['sometimes', 'nullable', 'uuid'],
            'auto_renew'          => ['sometimes', 'boolean'],
        ]);

        try {
            $quote = $this->quotes->createQuote($request->user(), $data);

            return response()->json([
                'success' => true,
                'data'    => $this->quotes->responseData($quote),
            ], 201);
        } catch (CheckoutQuoteException $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->status);
        } catch (\Throwable $e) {
            Log::error('Error creating checkout quote: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar la cotización.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
