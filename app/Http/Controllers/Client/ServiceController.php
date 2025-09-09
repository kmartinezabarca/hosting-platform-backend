<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use Stripe\Stripe;
use App\Models\Service;
use App\Models\ServicePlan;
use App\Models\ServiceInvoice;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use App\Models\Subscription;
use App\Models\Invoice;
use App\Models\ActivityLog;
use App\Models\PaymentMethod;
use App\Models\ServiceAddOn;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\PaymentIntent as StripePaymentIntent;



class ServiceController extends Controller
{
    public function __construct()
    {
        // Set Stripe API key
        Stripe::setApiKey(env("STRIPE_SECRET"));
    }

    /**
     * Get available service plans
     */
    public function getServicePlans(): JsonResponse
    {
        try {
            $plans = ServicePlan::with(["category", "features", "pricing.billingCycle"])
                ->active()
                ->orderBy("sort_order")
                ->get()
                ->map(function ($plan) {
                    $planData = [
                        "id" => $plan->id,
                        "uuid" => $plan->uuid,
                        "slug" => $plan->slug,
                        "name" => $plan->name,
                        "description" => $plan->description,
                        "base_price" => $plan->base_price,
                        "setup_fee" => $plan->setup_fee,
                        "is_popular" => $plan->is_popular,
                        "category" => $plan->category ? $plan->category->name : null,
                        "category_slug" => $plan->category ? $plan->category->slug : null,
                        "specifications" => $plan->specifications,
                        "features" => $plan->features->pluck("name")->toArray(),
                        "pricing" => $plan->pricing->map(function ($pricing) {
                            return [
                                "billing_cycle" => $pricing->billingCycle->name,
                                "billing_cycle_slug" => $pricing->billingCycle->slug,
                                "price" => $pricing->price,
                                "discount_percentage" => $pricing->discount_percentage,
                            ];
                        })->toArray()
                    ];

                    return $planData;
                });

            return response()->json([
                "success" => true,
                "data" => $plans
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching service plans: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error fetching service plans"
            ], 500);
        }
    }

    /**
     * Contrata un servicio (Pago único con Stripe + snapshot de add-ons + factura).
     *
     * Soporta:
     *  - Flujo A: el frontend confirma un PaymentIntent y envía payment_intent_id.
     *  - Flujo B: el frontend envía payment_method_id (tarjeta guardada) y aquí creamos/confirmamos el PaymentIntent.
     */
    public function contractService(Request $request): JsonResponse
    {

        try {
            $validated = $request->validate([
                'plan_id'            => ['required', 'string', Rule::exists('service_plans', 'slug')],
                'billing_cycle'      => ['required', Rule::in(['monthly', 'quarterly', 'annually'])],
                'domain'             => ['nullable', 'string', 'max:255'],
                'service_name'       => ['required', 'string', 'max:255'],

                // Acepta uno u otro
                'payment_intent_id'  => ['required_without:payment_method_id', 'string'],
                'payment_method_id'  => ['required_without:payment_intent_id', 'string'],

                'additional_options' => ['nullable', 'array'],

                // Add-ons por UUID
                'add_ons'            => ['sometimes', 'array'],
                'add_ons.*'          => ['string', 'distinct'],

                // Datos de facturación (opcionales)
                'invoice'            => ['sometimes', 'array'],
                'invoice.rfc'        => ['required_with:invoice', 'string', 'max:13'],
                'invoice.name'       => ['required_with:invoice', 'string', 'max:255'],
                'invoice.zip'        => ['required_with:invoice', 'string', 'size:5'],
                'invoice.regimen'    => ['required_with:invoice', 'string', 'max:4'],
                'invoice.uso_cfdi'   => ['required_with:invoice', 'string', 'max:10'],
                'invoice.constancia' => ['nullable', 'string'], // base64 u otra representación

                'create_subscription' => ['sometimes', 'boolean'],
            ]);

            $user = Auth::user();
            $plan = ServicePlan::where('slug', $validated['plan_id'])->firstOrFail();

            // ------ Precios ------
            $multiplier = match ($validated['billing_cycle']) {
                'monthly'   => 1,
                'quarterly' => 3,
                'annually'  => 12,
            };

            // Add-ons permitidos por el plan
            $requestedUuids  = collect($validated['add_ons'] ?? []);
            $allowedAddOns   = $plan->addOns()->where('is_active', true)->get(); // requiere relación en ServicePlan
            $selectedAddOns  = $allowedAddOns->whereIn('uuid', $requestedUuids);

            if ($requestedUuids->isNotEmpty() && $selectedAddOns->count() !== $requestedUuids->count()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Algunos add-ons no existen o no están permitidos por este plan.',
                ], 422);
            }

            // Precios NETOS (sin IVA)
            $planNet    = round((float) $plan->base_price * $multiplier, 2);
            $addonsNet  = round($selectedAddOns->sum(fn($a) => (float) $a->price) * $multiplier, 2);
            $subtotal   = round($planNet + $addonsNet, 2);

            $taxRatePct = (float) config('billing.tax_rate_percent', 16.00); // 16%
            $taxRate    = $taxRatePct / 100;
            $taxAmount  = round($subtotal * $taxRate, 2);
            $total      = round($subtotal + $taxAmount, 2);
            $currency   = $plan->currency ? strtoupper($plan->currency) : 'MXN';

            $nextBillingDate = now()->addMonths($multiplier);
            $amountInCents   = (int) round($total * 100);

            // ------ Normalización Stripe (PI / PM) ------
            // Asegura Customer en Stripe (via Cashier o similar)
            if (method_exists($user, 'createOrGetStripeCustomer')) {
                $user->createOrGetStripeCustomer();
            }
            $customerId = $user->stripe_id ?? null;

            $pi               = null;   // PaymentIntent final usado
            $usedStripePmId   = null;   // pm_... usado finalmente
            $pmObject         = null;   // Objeto PaymentMethod
            $localPaymentMethodId = null;

            // Si nos dieron ID local o pm_ de Stripe en payment_method_id, intenta mapear a tu tabla local si aplica
            if (!empty($validated['payment_method_id'])) {
                $providedPm = $validated['payment_method_id'];
                if (is_numeric($providedPm)) {
                    $local = PaymentMethod::where('user_id', $user->id)->find((int) $providedPm);
                    if ($local) $localPaymentMethodId = $local->id;
                } elseif (is_string($providedPm) && str_starts_with($providedPm, 'pm_')) {
                    $local = PaymentMethod::where('user_id', $user->id)
                        ->where('stripe_payment_method_id', $providedPm)
                        ->first();
                    if ($local) $localPaymentMethodId = $local->id;
                }
            }

            // ---- FLUJO A: viene payment_intent_id (el front ya confirmó la tarjeta) ----
            if (!empty($validated['payment_intent_id'])) {
                $pi = StripePaymentIntent::retrieve([
                    'id'     => $validated['payment_intent_id'],
                    'expand' => ['payment_method'],
                ]);

                // Normaliza payment_method (puede venir como string)
                if (is_string($pi->payment_method)) {
                    $usedStripePmId = $pi->payment_method;
                    $pmObject = StripePaymentMethod::retrieve($usedStripePmId);
                } else {
                    $pmObject = $pi->payment_method;
                    $usedStripePmId = $pmObject?->id;
                }

                // ---- FLUJO B: viene solo payment_method_id (tarjeta guardada) ----
            } else {
                $usedStripePmId = $validated['payment_method_id'];

                // Recupera PM para saber si ya está adjunto a un customer
                $pmObject = StripePaymentMethod::retrieve($usedStripePmId);
                $pmCustomerId = $pmObject->customer; // null o 'cus_...'

                // El PM debe pertenecer a este usuario si ya tiene customer
                if ($pmCustomerId && $customerId && $pmCustomerId !== $customerId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El método de pago no pertenece a este usuario.',
                    ], 422);
                }

                // Si no está adjunto, adjunta al customer del usuario
                if (!$pmCustomerId) {
                    if (!$customerId) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No se pudo determinar el cliente de Stripe.',
                        ], 422);
                    }
                    StripePaymentMethod::attach($usedStripePmId, ['customer' => $customerId]);
                    $pmCustomerId = $customerId;
                }

                // Crea y confirma el PaymentIntent (off_session para no pedir 3DS si no es necesario)
                $pi = StripePaymentIntent::create([
                    'amount'         => $amountInCents,
                    'currency'       => strtolower($currency), // 'mxn'
                    'customer'       => $pmCustomerId,         // <- CLAVE
                    'payment_method' => $usedStripePmId,
                    'confirm'        => true,
                    'off_session'    => true,
                    'description'    => 'Pago de contratación de servicio',
                ]);

                // Si requiere 3DS/acción adicional => devolvemos client_secret para que el front lo maneje
                if ($pi->status === 'requires_action') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Se requiere autenticación adicional del banco.',
                        'data' => [
                            'client_secret'   => $pi->client_secret,
                            'requires_action' => true,
                        ],
                    ], 402);
                }

                // Para unificar el resto, establece payment_intent_id
                $validated['payment_intent_id'] = $pi->id;

                // Recarga expandiendo PM
                $pi = StripePaymentIntent::retrieve([
                    'id'     => $pi->id,
                    'expand' => ['payment_method'],
                ]);

                if (is_string($pi->payment_method)) {
                    $usedStripePmId = $pi->payment_method;
                    $pmObject = StripePaymentMethod::retrieve($usedStripePmId);
                } else {
                    $pmObject = $pi->payment_method;
                    $usedStripePmId = $pmObject?->id;
                }
            }

            // Extra card meta (robusto: pmObject es objeto)
            $cardMeta = null;
            if ($pmObject && $pmObject->type === 'card' && isset($pmObject->card)) {
                $c = $pmObject->card;
                $cardMeta = [
                    'brand'     => $c->brand,
                    'last4'     => $c->last4,
                    'exp_month' => $c->exp_month,
                    'exp_year'  => $c->exp_year,
                    'funding'   => $c->funding,
                    'country'   => $c->country ?? null,
                ];
            }

            // Si no teníamos método local y el pm_ coincide con alguno guardado, úsalo
            if (!$localPaymentMethodId && $usedStripePmId) {
                $local = PaymentMethod::where('user_id', $user->id)
                    ->where('stripe_payment_method_id', $usedStripePmId)
                    ->first();
                if ($local) {
                    $localPaymentMethodId = $local->id;
                }
            }

            DB::beginTransaction();

            // 1) SERVICE
            $service = Service::create([
                'plan_id'           => $plan->id,
                'user_id'           => $user->id,
                'price'             => $plan->base_price, // NETO unitario del plan
                'name'              => $validated['service_name'],
                'status'            => 'active',
                'billing_cycle'     => $validated['billing_cycle'],
                'domain'            => $validated['domain'] ?? null,
                'payment_intent_id' => $validated['payment_intent_id'],
                'configuration'     => $validated['additional_options'] ?? null,
                'next_due_date'     => $nextBillingDate,
            ]);

            // 1.1) Snapshot add-ons
            foreach ($selectedAddOns as $addOn) {
                ServiceAddOn::create([
                    'service_id'  => $service->id,
                    'add_on_id'   => $addOn->id,
                    'add_on_uuid' => $addOn->uuid,
                    'name'        => $addOn->name,
                    'unit_price'  => $addOn->price, // NETO por ciclo
                    'quantity'    => 1,
                ]);
            }

            // 2) Datos fiscales del servicio (opcional)
            if (!empty($validated['invoice'])) {
                ServiceInvoice::create([
                    'service_id'  => $service->id,
                    'rfc'         => $validated['invoice']['rfc'],
                    'name'        => $validated['invoice']['name'],
                    'zip'         => $validated['invoice']['zip'],
                    'regimen'     => $validated['invoice']['regimen'],
                    'uso_cfdi'    => $validated['invoice']['uso_cfdi'],
                    'constancia'  => $validated['invoice']['constancia'] ?? null,
                ]);
            }

            // 3) INVOICE (pago upfront exitoso => paid) - guarda BRUTO y desglose
            $invoice = Invoice::create([
                'user_id'             => $user->id,
                'service_id'          => $service->id,
                'status'              => 'paid',
                'due_date'            => now(),
                'paid_at'             => now(),
                'invoice_number'      => $this->generateInvoiceNumber(),
                'provider_invoice_id' => null,
                'pdf_path'            => null,
                'xml_path'            => null,
                'payment_method'      => 'stripe',
                'payment_reference'   => $validated['payment_intent_id'],
                'notes'               => 'Pago por contratación de servicio',
                'currency'            => $currency,
                'subtotal'            => $subtotal,   // NETO
                'tax_rate'            => $taxRatePct, // 16.00
                'tax_amount'          => $taxAmount,  // IVA
                'total'               => $total,      // BRUTO
            ]);

            // 4) INVOICE ITEMS (plan + add-ons) — NETO
            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'service_id'  => $service->id,
                'description' => sprintf('%s (%s)', $plan->name, strtoupper($validated['billing_cycle'])),
                'quantity'    => 1,
                'unit_price'  => $planNet,
                'total'       => $planNet,
            ]);

            foreach ($selectedAddOns as $addOn) {
                $rowNet = round((float) $addOn->price * $multiplier, 2);
                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'service_id'  => $service->id,
                    'description' => $addOn->name,
                    'quantity'    => 1,
                    'unit_price'  => $rowNet,
                    'total'       => $rowNet,
                ]);
            }

            // 5) TRANSACTION (monto BRUTO)
            $providerData = [
                'stripe' => [
                    'payment_intent_id' => $pi->id ?? $validated['payment_intent_id'],
                    'payment_method_id' => $usedStripePmId,
                    'status'            => $pi->status ?? 'succeeded',
                    'card'              => $cardMeta,
                ],
                'note' => $localPaymentMethodId ? 'Pago con método guardado del cliente.' : 'Pago con tarjeta no guardada (one-off).',
            ];

            Transaction::create([
                'uuid'                    => (string) Str::uuid(),
                'user_id'                 => $user->id,
                'invoice_id'              => $invoice->id,
                'payment_method_id'       => $localPaymentMethodId,
                'transaction_id'          => 'TRX-' . Str::upper(Str::random(10)),
                'provider_transaction_id' => $validated['payment_intent_id'],
                'type'                    => 'payment',
                'status'                  => 'completed',
                'amount'                  => $total,     // incluye IVA
                'currency'                => $currency,
                'fee_amount'              => 0,
                'provider'                => 'stripe',
                'provider_data'           => $providerData,
                'description'             => 'Pago de contratación de servicio',
                'failure_reason'          => null,
                'processed_at'            => now(),
            ]);

            ActivityLog::record(
                'Pago de servicio',
                $plan->name,
                'payment',
                [
                    'invoice_id' => $invoice->id,
                    'service_id' => $service->id,
                    'amount'     => $total,
                    'currency'   => $currency,
                ],
                $user->id
            );

            // 6) (Opcional) suscripción Stripe
            if (!empty($validated['create_subscription'])) {
                if (!$plan->stripe_price_id) {
                    throw new \RuntimeException('El plan no tiene stripe_price_id configurado.');
                }
                if (!$customerId) {
                    if (method_exists($user, 'createOrGetStripeCustomer')) {
                        $user->createOrGetStripeCustomer();
                        $customerId = $user->stripe_id;
                    }
                }

                $stripeSub = \Stripe\Subscription::create([
                    'customer' => $customerId,
                    'items'    => [['price' => $plan->stripe_price_id]],
                    'payment_behavior' => 'default_incomplete',
                    'expand' => ['latest_invoice.payment_intent'],
                ]);

                Subscription::create([
                    'uuid'                   => (string) Str::uuid(),
                    'user_id'                => $user->id,
                    'service_id'             => $service->id,
                    'stripe_subscription_id' => $stripeSub->id,
                    'stripe_customer_id'     => $customerId,
                    'stripe_price_id'        => $plan->stripe_price_id,
                    'name'                   => $plan->name,
                    'status'                 => $stripeSub->status,
                    'amount'                 => $total,
                    'currency'               => $currency,
                    'billing_cycle'          => $validated['billing_cycle'] === 'annually' ? 'yearly'
                        : ($validated['billing_cycle'] === 'monthly' ? 'monthly' : 'monthly'),
                    'current_period_start'   => isset($stripeSub->current_period_start) ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_start) : null,
                    'current_period_end'     => isset($stripeSub->current_period_end) ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end) : null,
                    'trial_start'            => isset($stripeSub->trial_start) ? \Carbon\Carbon::createFromTimestamp($stripeSub->trial_start) : null,
                    'trial_end'              => isset($stripeSub->trial_end) ? \Carbon\Carbon::createFromTimestamp($stripeSub->trial_end) : null,
                    'ends_at'                => isset($stripeSub->current_period_end) ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end) : null,
                    'created_at'             => \Carbon\Carbon::createFromTimestamp($stripeSub->created),
                ]);

                ActivityLog::record(
                    'Suscripción creada',
                    "Suscripción para el plan {$plan->name} creada en Stripe.",
                    'subscription',
                    ['user_id' => $user->id, 'plan_id' => $plan->id, 'stripe_sub_id' => $stripeSub->id],
                    $user->id
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Service contracted successfully',
                'service' => $service,
                'invoice' => $invoice,
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            Log::warning('Validation error contracting service', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Stripe\Exception\CardException $e) {
            DB::rollBack();
            // Errores de tarjeta (fondos insuficientes, etc.)
            return response()->json([
                'success' => false,
                'message' => $e->getError()->message ?? 'Payment failed',
            ], 402);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error contracting service: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Error contracting service',
                'error'   => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 500);
        }
    }


    /**
     * Get user's services
     */
    public function getUserServices(): JsonResponse
    {
        try {
            $user = Auth::user();
            $services = Service::where("user_id", $user->id)
                ->with(["plan", "plan.category", "plan.features"])
                ->orderByDesc("created_at")
                ->get();

            return response()->json([
                "success" => true,
                "data" => $services
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching user services: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error fetching user services"
            ], 500);
        }
    }

    /**
     * Get service details
     */
    public function getServiceDetails(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->with(["plan", "plan.category", "plan.features"])
                ->firstOrFail();

            return response()->json([
                "success" => true,
                "data" => $service
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching service details: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Service not found or not authorized"
            ], 404);
        }
    }

    public function getServiceInvoices(Request $request, $uuid)
    {
        $service = Service::where('uuid', $uuid)->where('user_id', $request->user()->id)->firstOrFail();

        $invoices = $service->invoice()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $invoices,
        ]);
    }

    public function updateConfiguration(Request $request, $uuid)
    {
        $service = Service::where('uuid', $uuid)->where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'auto_renew' => 'required|boolean',
        ]);

        // El ->cast('array') en el modelo Service se encarga de esto
        $currentConfig = $service->configuration;
        $currentConfig['auto_renew'] = $validated['auto_renew'];
        $service->configuration = $currentConfig;

        $service->save();

        return response()->json([
            'success' => true,
            'message' => 'Configuración actualizada correctamente.',
            'data' => $service,
        ]);
    }

    /**
     * Update service configuration
     */
    public function updateServiceConfig(Request $request, string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            $validated = $request->validate([
                "configuration" => "required|array",
            ]);

            $service->update([
                "configuration" => $validated["configuration"]
            ]);

            ActivityLog::record(
                "Configuración de servicio actualizada",
                "Configuración del servicio " . $service->name . " (" . $service->uuid . ") actualizada.",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id, "new_config" => $validated["configuration"]],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Service configuration updated successfully",
                "data" => $service->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error("Error updating service configuration: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error updating service configuration"
            ], 500);
        }
    }

    /**
     * Cancel a service
     */
    public function cancelService(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            $service->update([
                "status" => "cancelled",
                "cancelled_at" => now(),
            ]);

            ActivityLog::record(
                "Servicio cancelado",
                "El servicio " . $service->name . " (" . $service->uuid . ") ha sido cancelado.",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Service cancelled successfully",
                "data" => $service->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error("Error cancelling service: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error cancelling service"
            ], 500);
        }
    }

    /**
     * Suspend a service
     */
    public function suspendService(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            $service->update([
                "status" => "suspended",
                "suspended_at" => now(),
            ]);

            ActivityLog::record(
                "Servicio suspendido",
                "El servicio " . $service->name . " (" . $service->uuid . ") ha sido suspendido.",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Service suspended successfully",
                "data" => $service->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error("Error suspending service: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error suspending service"
            ], 500);
        }
    }

    /**
     * Reactivate a service
     */
    public function reactivateService(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            $service->update([
                "status" => "active",
                "suspended_at" => null,
            ]);

            ActivityLog::record(
                "Servicio reactivado",
                "El servicio " . $service->name . " (" . $service->uuid . ") ha sido reactivado.",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Service reactivated successfully",
                "data" => $service->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error("Error reactivating service: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error reactivating service"
            ], 500);
        }
    }

    /**
     * Get service usage statistics
     */
    public function getServiceUsage(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            // For now, return dummy data. In a real scenario, this would fetch from monitoring systems.
            $usageData = [
                "cpu_usage" => rand(10, 90),
                "memory_usage" => rand(20, 80),
                "disk_usage" => rand(5, 95),
                "bandwidth_usage" => rand(100, 1000),
                "last_updated" => now()->toDateTimeString(),
            ];

            return response()->json([
                "success" => true,
                "data" => $usageData
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching service usage: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error fetching service usage"
            ], 500);
        }
    }

    /**
     * Get service backups
     */
    public function getServiceBackups(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            // Dummy data for backups. In a real app, this would fetch from a backup system.
            $backups = [
                ["id" => Str::uuid(), "date" => now()->subDays(1)->toDateTimeString(), "size_mb" => 500, "type" => "full"],
                ["id" => Str::uuid(), "date" => now()->subDays(3)->toDateTimeString(), "size_mb" => 200, "type" => "incremental"],
                ["id" => Str::uuid(), "date" => now()->subDays(7)->toDateTimeString(), "size_mb" => 700, "type" => "full"],
            ];

            return response()->json([
                "success" => true,
                "data" => $backups
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching service backups: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error fetching service backups"
            ], 500);
        }
    }

    /**
     * Create a service backup
     */
    public function createServiceBackup(string $serviceId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            // Simulate backup creation process
            sleep(2); // Simulate a delay

            $newBackup = [
                "id" => Str::uuid(),
                "date" => now()->toDateTimeString(),
                "size_mb" => rand(100, 1000),
                "type" => "full",
                "status" => "completed"
            ];

            ActivityLog::record(
                "Copia de seguridad de servicio creada",
                "Copia de seguridad para el servicio " . $service->name . " (" . $service->uuid . ") creada.",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id, "backup_id" => $newBackup["id"]],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Backup created successfully",
                "data" => $newBackup
            ], 201);
        } catch (\Exception $e) {
            Log::error("Error creating service backup: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error creating service backup"
            ], 500);
        }
    }

    /**
     * Restore a service backup
     */
    public function restoreServiceBackup(string $serviceId, string $backupId): JsonResponse
    {
        try {
            $user = Auth::user();
            $service = Service::where("user_id", $user->id)
                ->where("uuid", $serviceId)
                ->firstOrFail();

            // Simulate backup restoration process
            sleep(3); // Simulate a delay

            ActivityLog::record(
                "Restauración de servicio desde copia de seguridad",
                "Servicio " . $service->name . " (" . $service->uuid . ") restaurado desde la copia de seguridad " . $backupId . ".",
                "service",
                ["user_id" => $user->id, "service_id" => $service->id, "backup_id" => $backupId],
                $user->id
            );

            return response()->json([
                "success" => true,
                "message" => "Service restored successfully from backup " . $backupId
            ]);
        } catch (\Exception $e) {
            Log::error("Error restoring service backup: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error restoring service backup"
            ], 500);
        }
    }

    /**
     * Get or create Stripe customer
     */
    private function getOrCreateStripeCustomer($user)
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = \Stripe\Customer::create([
            "email" => $user->email,
            "name" => $user->first_name . " " . $user->last_name,
            "metadata" => [
                "user_id" => $user->id,
                "uuid" => $user->uuid,
            ],
        ]);

        $user->stripe_customer_id = $customer->id;
        $user->save();

        return $customer->id;
    }

    /**
     * Generate unique invoice number
     */
    private function generateInvoiceNumber(): string
    {
        $lastInvoice = Invoice::orderByDesc("created_at")->first();
        $lastNumber = $lastInvoice ? (int) substr($lastInvoice->invoice_number, 4) : 0;
        $newNumber = $lastNumber + 1;
        return "INV-" . str_pad($newNumber, 6, "0", STR_PAD_LEFT);
    }
}
