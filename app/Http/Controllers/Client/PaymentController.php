<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\CheckoutQuoteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StorePaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Http\Resources\TransactionResource;
use App\Models\ActivityLog;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\CheckoutQuoteService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService)
    {
    }

    // ──────────────────────────────────────────────
    // Payment Methods
    // ──────────────────────────────────────────────

    public function getPaymentMethods(): JsonResponse
    {
        $user = Auth::user();

        $methods = PaymentMethod::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => PaymentMethodResource::collection($methods),
        ]);
    }

    public function createSetupIntent(): JsonResponse
    {
        try {
            $user        = Auth::user();
            $setupIntent = $this->paymentService->createSetupIntent($user);

            ActivityLog::record(
                'Creación de Setup Intent',
                'Setup Intent creado para agregar método de pago.',
                'payment_method',
                ['user_id' => $user->id, 'setup_intent_id' => $setupIntent->id],
                $user->id
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'client_secret'   => $setupIntent->client_secret,
                    'setup_intent_id' => $setupIntent->id,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error creating setup intent: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el setup intent.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function createPaymentIntentFromQuote(Request $request, CheckoutQuoteService $quotes): JsonResponse
    {
        $data = $request->validate([
            'quote_id'            => ['required', 'uuid'],
            'payment_method_id'   => ['sometimes', 'nullable', 'string'],
            'create_subscription' => ['sometimes', 'boolean'],
        ]);

        try {
            $user = Auth::user();
            $quote = $quotes->validateQuote($data['quote_id'], $user);
            $amountCents = (int) round((float) $quote->total * 100);

            if ($amountCents <= 0) {
                return response()->json([
                    'success' => true,
                    'data'    => [
                        'quote_id'            => $quote->uuid,
                        'payment_required'    => false,
                        'client_secret'       => null,
                        'payment_intent_id'   => null,
                        'amount'              => 0,
                        'currency'            => $quote->currency,
                        'create_subscription' => (bool) ($data['create_subscription'] ?? true),
                    ],
                ]);
            }

            $intent = $this->paymentService->createPaymentIntent(
                $user,
                $amountCents,
                $quote->currency,
                [
                    'quote_id'            => $quote->uuid,
                    'checkout_quote_id'   => $quote->id,
                    'service_plan_id'     => $quote->service_plan_id,
                    'billing_cycle_id'    => $quote->billing_cycle_id,
                    'create_subscription' => (string) (int) ($data['create_subscription'] ?? true),
                ],
                'ROKE Industries — checkout quote ' . $quote->uuid
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'quote_id'            => $quote->uuid,
                    'payment_required'    => true,
                    'client_secret'       => $intent->client_secret,
                    'payment_intent_id'   => $intent->id,
                    'amount'              => (float) $quote->total,
                    'amount_cents'        => $amountCents,
                    'currency'            => $quote->currency,
                    'create_subscription' => (bool) ($data['create_subscription'] ?? true),
                ],
            ], 201);
        } catch (CheckoutQuoteException $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->status);
        } catch (\Throwable $e) {
            Log::error('Error creating payment intent from quote: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el intento de pago.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function addPaymentMethod(StorePaymentMethodRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $data = $request->validated();

            $paymentMethod = $this->paymentService->attachPaymentMethod(
                $user,
                $data['stripe_payment_method_id'],
                (bool) ($data['is_default'] ?? false),
                $data['name'] ?? null
            );

            ActivityLog::record(
                'Método de pago agregado',
                "Método de pago {$paymentMethod->name} agregado.",
                'payment_method',
                ['user_id' => $user->id, 'payment_method_id' => $paymentMethod->id],
                $user->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Método de pago agregado exitosamente.',
                'data'    => new PaymentMethodResource($paymentMethod),
            ], 201);
        } catch (\RuntimeException $e) {
            $map = [
                'payment_method_already_saved' => 'Este método de pago ya está registrado en tu cuenta.',
                'attached_to_other_customer'   => 'Este método de pago ya está vinculado a otra cuenta.',
            ];

            return response()->json([
                'success' => false,
                'message' => $map[$e->getMessage()] ?? 'No se pudo agregar el método de pago.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe error adding payment method: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error de Stripe al agregar el método de pago.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error adding payment method: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al agregar el método de pago.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function updatePaymentMethod(Request $request, string $uuid): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'       => ['sometimes', 'string', 'max:100'],
                'is_default' => ['sometimes', 'boolean'],
            ]);

            $user          = Auth::user();
            $paymentMethod = PaymentMethod::where('user_id', $user->id)
                ->where('uuid', $uuid)
                ->firstOrFail();

            if (!empty($validated['is_default'])) {
                PaymentMethod::where('user_id', $user->id)
                    ->where('uuid', '!=', $uuid)
                    ->update(['is_default' => false]);
            }

            $paymentMethod->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Método de pago actualizado.',
                'data'    => new PaymentMethodResource($paymentMethod->fresh()),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error updating payment method: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el método de pago.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function deletePaymentMethod(string $uuid): JsonResponse
    {
        try {
            $user          = Auth::user();
            $paymentMethod = PaymentMethod::where('user_id', $user->id)
                ->where('uuid', $uuid)
                ->firstOrFail();

            $activeMethodsCount = PaymentMethod::where('user_id', $user->id)
                ->where('is_active', true)
                ->count();

            if ($paymentMethod->is_default && $activeMethodsCount <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'No puedes eliminar tu único método de pago predeterminado.',
                ], 422);
            }

            $this->paymentService->detachPaymentMethod($paymentMethod);

            ActivityLog::record(
                'Método de pago eliminado',
                "Método de pago {$paymentMethod->name} eliminado.",
                'payment_method',
                ['user_id' => $user->id, 'payment_method_id' => $paymentMethod->id],
                $user->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Método de pago eliminado exitosamente.',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Let the global handler translate this to 404 — do NOT catch it as a 500
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Error deleting payment method: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el método de pago.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ──────────────────────────────────────────────
    // Transactions
    // ──────────────────────────────────────────────

    public function getTransactions(Request $request): JsonResponse
    {
        $user    = Auth::user();
        $perPage = min((int) $request->get('per_page', 15), 100);

        $transactions = Transaction::where('user_id', $user->id)
            ->with(['invoice', 'paymentMethod'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => TransactionResource::collection($transactions)->response()->getData(true),
        ]);
    }

    // ──────────────────────────────────────────────
    // Statistics
    // ──────────────────────────────────────────────

    public function getPaymentStats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->paymentService->getUserStats(Auth::user()),
        ]);
    }
}
