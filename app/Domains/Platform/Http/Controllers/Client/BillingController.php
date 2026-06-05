<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Estado de facturación del cliente para banners del dashboard.
 *
 * El frontend consume GET /api/billing/banners y muestra avisos claros:
 * pago fallido + días de gracia, servicio suspendido, servicio aprovisionando.
 */
class BillingController extends Controller
{
    public function banners(): JsonResponse
    {
        $userId  = Auth::id();
        $banners = [];

        // ── Suscripciones en gracia por pago fallido ──────────────────────────
        $pastDue = Subscription::where('user_id', $userId)
            ->where('status', 'past_due')
            ->whereNotNull('grace_period_ends_at')
            ->with('service')
            ->get();

        foreach ($pastDue as $sub) {
            $daysLeft = max(0, (int) ceil(now()->diffInHours($sub->grace_period_ends_at, false) / 24));

            $banners[] = [
                'type'                 => 'payment_failed',
                'severity'             => 'warning',
                'title'                => 'Tu pago no pudo procesarse',
                'message'              => "Tienes {$daysLeft} día(s) para actualizar tu método de pago antes de que el servicio sea suspendido.",
                'subscription_id'      => $sub->uuid,
                'service_id'           => $sub->service?->uuid,
                'service_name'         => $sub->service?->name,
                'grace_period_ends_at' => $sub->grace_period_ends_at?->toIso8601String(),
                'days_left'            => $daysLeft,
                'action'               => ['label' => 'Actualizar pago', 'route' => '/billing/payment-methods'],
            ];
        }

        // ── Servicios suspendidos por morosidad ───────────────────────────────
        $suspended = Service::where('user_id', $userId)
            ->where('status', 'suspended')
            ->where('suspension_reason', 'payment_overdue')
            ->get();

        foreach ($suspended as $service) {
            $banners[] = [
                'type'         => 'service_suspended',
                'severity'     => 'error',
                'title'        => 'Servicio suspendido',
                'message'      => "Tu servicio '{$service->name}' fue suspendido por falta de pago. Actualiza tu método de pago para reactivarlo.",
                'service_id'   => $service->uuid,
                'service_name' => $service->name,
                'action'       => ['label' => 'Reactivar', 'route' => '/billing/payment-methods'],
            ];
        }

        // ── Servicios aprovisionándose ────────────────────────────────────────
        $provisioning = Service::where('user_id', $userId)
            ->where(function ($q) {
                $q->where('status', 'pending')
                  ->orWhereIn('provisioning_status', ['pending', 'provisioning']);
            })
            ->get();

        foreach ($provisioning as $service) {
            $banners[] = [
                'type'         => 'provisioning',
                'severity'     => 'info',
                'title'        => 'Preparando tu servicio',
                'message'      => "Estamos configurando '{$service->name}'. Te avisaremos cuando esté listo.",
                'service_id'   => $service->uuid,
                'service_name' => $service->name,
            ];
        }

        // ── Servicios con aprovisionamiento fallido (tras reintentos) ─────────
        $provisioningFailed = Service::where('user_id', $userId)
            ->where('provisioning_status', 'failed')
            ->get();

        foreach ($provisioningFailed as $service) {
            $banners[] = [
                'type'         => 'provisioning_failed',
                'severity'     => 'error',
                'title'        => 'No pudimos preparar tu servicio',
                'message'      => "Hubo un problema al configurar '{$service->name}'. Nuestro equipo fue notificado; también puedes contactar a soporte.",
                'service_id'   => $service->uuid,
                'service_name' => $service->name,
                'action'       => ['label' => 'Contactar soporte', 'route' => '/support'],
            ];
        }

        return response()->json(['success' => true, 'data' => $banners]);
    }
}
