<?php

namespace App\Services;

use App\Events\UserRegistered;
use App\Events\PasswordResetRequested;
use App\Events\PurchaseCompleted;
use App\Events\PaymentProcessed;
use App\Events\InvoiceGenerated;
use App\Events\ServiceNotificationSent;
use App\Events\AccountUpdated;
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
     * Send payment success email
     */
    public function sendPaymentSuccessEmail(User $user, $payment = null, $subscription = null, $services = null, $invoiceUrl = null, $isRecurring = false)
    {
        event(new PaymentProcessed($user, $payment, $subscription, $services, $invoiceUrl, $isRecurring));
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
        $users = User::all();
        
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
    }

    /**
     * Send outage notification to all users
     */
    public function sendOutageNotification($outageStatus = 'Investigando', $outageStart = 'Hace unos minutos', $affectedServices = 'Hosting web')
    {
        $users = User::all();
        
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

