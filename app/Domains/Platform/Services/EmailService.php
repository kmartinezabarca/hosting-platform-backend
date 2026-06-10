<?php

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Events\UserRegistered;
use App\Domains\Platform\Events\PasswordResetRequested;
use App\Domains\Platform\Events\PurchaseCompleted;
use App\Domains\Platform\Events\InvoiceGenerated;
use App\Domains\Platform\Events\ServiceNotificationSent;
use App\Domains\Platform\Events\AccountUpdated;
use App\Models\User;

class EmailService
{
    /**
     * Send welcome email to new user
     */
    public function sendWelcomeEmail(User $user, $loginUrl = null)
    {
        event(new UserRegistered($user, $loginUrl));
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(User $user, $resetUrl, $ipAddress = null)
    {
        event(new PasswordResetRequested($user, $resetUrl, $ipAddress));
    }

    /**
     * Send purchase confirmation email
     */
    public function sendPurchaseConfirmationEmail(User $user, $order = null, $items = null, $total = 0, $paymentMethod = null, $dashboardUrl = null, $serviceName = null, $serviceDescription = null)
    {
        event(new PurchaseCompleted($user, $order, $items, $total, $paymentMethod, $dashboardUrl, $serviceName, $serviceDescription));
    }

    /**
     * Send payment success email.
     *
     * Nota: el evento canónico PaymentProcessed es Transaction-based y ya gobierna
     * las notificaciones de pago (in-app + correo vía PaymentNotification). Este
     * helper solo envía el correo de confirmación, reutilizando el flujo de
     * notificación de servicio que sí está implementado.
     */
    public function sendPaymentSuccessEmail(User $user, $payment = null, $subscription = null, $services = null, $invoiceUrl = null, $isRecurring = false)
    {
        $amount = is_object($payment) ? ($payment->amount ?? null) : null;

        $this->sendServiceNotificationEmail(
            $user,
            'payment',
            $amount
                ? "Tu pago de {$amount} fue procesado exitosamente."
                : 'Tu pago fue procesado exitosamente.',
            (bool) $invoiceUrl,
            $invoiceUrl,
            $invoiceUrl ? 'Ver factura' : null,
            ['isRecurring' => $isRecurring]
        );
    }

    /**
     * Send invoice generated email
     */
    public function sendInvoiceGeneratedEmail(User $user, $invoice = null, $invoiceItems = null, $downloadUrl = null)
    {
        event(new InvoiceGenerated($user, $invoice, $invoiceItems, $downloadUrl));
    }

    /**
     * Send service notification email
     */
    public function sendServiceNotificationEmail(User $user, $notificationType = null, $message = null, $actionRequired = false, $actionUrl = null, $actionText = null, $additionalData = [])
    {
        event(new ServiceNotificationSent($user, $notificationType, $message, $actionRequired, $actionUrl, $actionText, $additionalData));
    }

    /**
     * Send account update email
     */
    public function sendAccountUpdateEmail(User $user, $updateType = null, $updateDate = null, $ipAddress = null, $userAgent = null, $requiresAction = false, $actionUrl = null, $actionText = null, $changes = [])
    {
        event(new AccountUpdated($user, $updateType, $updateDate, $ipAddress, $userAgent, $requiresAction, $actionUrl, $actionText, $changes));
    }

    /**
     * Send maintenance notification to all users
     */
    public function sendMaintenanceNotification($maintenanceDate, $maintenanceDuration = '2 horas', $affectedServices = 'Todos los servicios de hosting')
    {
        // Procesar en lotes para no cargar todos los usuarios en memoria.
        User::chunkById(200, function ($users) use ($maintenanceDate, $maintenanceDuration, $affectedServices) {
            foreach ($users as $user) {
                $this->sendServiceNotificationEmail(
                    $user,
                    'maintenance',
                    'Mantenimiento programado en nuestros servidores',
                    false,
                    null,
                    null,
                    [
                        'maintenanceDate' => $maintenanceDate,
                        'maintenanceDuration' => $maintenanceDuration,
                        'affectedServices' => $affectedServices,
                    ]
                );
            }
        });
    }

    /**
     * Send outage notification to all users
     */
    public function sendOutageNotification($outageStatus = 'Investigando', $outageStart = 'Hace unos minutos', $affectedServices = 'Hosting web')
    {
        // Procesar en lotes para no cargar todos los usuarios en memoria.
        User::chunkById(200, function ($users) use ($outageStatus, $outageStart, $affectedServices) {
            foreach ($users as $user) {
                $this->sendServiceNotificationEmail(
                    $user,
                    'outage',
                    'Interrupción detectada en algunos servicios',
                    false,
                    null,
                    null,
                    [
                        'outageStatus' => $outageStatus,
                        'outageStart' => $outageStart,
                        'affectedServices' => $affectedServices,
                    ]
                );
            }
        });
    }

    /**
     * Send security alert to specific user
     */
    public function sendSecurityAlert(User $user, $securityLevel = 'Alto', $detectionDate = null)
    {
        $this->sendServiceNotificationEmail(
            $user,
            'security',
            'Actividad sospechosa detectada en tu cuenta',
            true,
            url('/dashboard/security'),
            'Revisar Seguridad',
            [
                'securityLevel' => $securityLevel,
                'detectionDate' => $detectionDate ?? now()->format('d/m/Y H:i'),
            ]
        );
    }
}

