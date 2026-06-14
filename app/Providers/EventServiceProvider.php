<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Logout;
use App\Domains\Platform\Listeners\MarkUserSessionLoggedOut;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        Logout::class => [
            MarkUserSessionLoggedOut::class,
        ],

        // Email Events
        \App\Domains\Platform\Events\UserRegistered::class => [
            \App\Domains\Platform\Listeners\SendWelcomeEmail::class,
        ],
        \App\Domains\Platform\Events\PasswordResetRequested::class => [
            \App\Domains\Platform\Listeners\SendPasswordResetEmail::class,
        ],
        // \App\Domains\Platform\Events\PurchaseCompleted::class => [
        //     \App\Domains\Platform\Listeners\SendPurchaseConfirmationEmail::class,
        // ],
        \App\Domains\Platform\Events\PaymentProcessed::class => [
            \App\Domains\Platform\Listeners\CreatePaymentNotification::class . '@handleProcessed',
        ],
        \App\Domains\Platform\Events\ServiceNotificationSent::class => [
            \App\Domains\Platform\Listeners\SendServiceNotificationEmail::class,
        ],
        \App\Domains\Platform\Events\AccountUpdated::class => [
            \App\Domains\Platform\Listeners\SendAccountUpdateEmail::class,
        ],

        // Service Events
        \App\Domains\Platform\Events\ServiceStatusChanged::class => [
            \App\Domains\Platform\Listeners\CreateServiceNotification::class . '@handleStatusChanged',
        ],
        \App\Domains\Platform\Events\ServicePurchased::class => [
            \App\Domains\Platform\Listeners\SendPurchaseConfirmationEmail::class,
            \App\Domains\Platform\Listeners\CreateServiceNotification::class . '@handlePurchased',
        ],
        \App\Domains\Platform\Events\ServiceReady::class => [
            \App\Domains\Platform\Listeners\CreateServiceNotification::class . '@handleReady',
        ],
        \App\Domains\Platform\Events\ServiceMaintenanceScheduled::class => [
            \App\Domains\Platform\Listeners\CreateServiceNotification::class . '@handleMaintenanceScheduled',
        ],
        \App\Domains\Platform\Events\ServiceMaintenanceCompleted::class => [
            \App\Domains\Platform\Listeners\CreateServiceNotification::class . '@handleMaintenanceCompleted',
        ],

        // Payment Events
        \App\Domains\Platform\Events\PaymentFailed::class => [
            \App\Domains\Platform\Listeners\CreatePaymentNotification::class . '@handleFailed',
        ],
        \App\Domains\Platform\Events\AutomaticPaymentProcessed::class => [
            \App\Domains\Platform\Listeners\CreatePaymentNotification::class . '@handleAutomaticProcessed',
        ],

        // Receipt / Invoice Events
        \App\Domains\Platform\Events\ReceiptGenerated::class => [
            \App\Domains\Platform\Listeners\CreateInvoiceNotification::class . '@handleGenerated',
        ],
        \App\Domains\Platform\Events\InvoiceGenerated::class => [
            \App\Domains\Platform\Listeners\SendInvoiceGeneratedEmail::class,
            \App\Domains\Platform\Listeners\CreateInvoiceNotification::class . '@handleGenerated',
        ],
        \App\Domains\Platform\Events\InvoiceStatusChanged::class => [
            \App\Domains\Platform\Listeners\CreateInvoiceNotification::class . '@handleStatusChanged',
        ],

        // Quotation Events — solo Aceptada/Rechazada avisan al equipo (panel +
        // correo). Vista/Reabierta quedan solo en el audit-log (sin ruido).
        \App\Domains\Platform\Events\QuotationAccepted::class => [
            \App\Domains\Platform\Listeners\NotifyQuotationResponse::class . '@handleAccepted',
        ],
        \App\Domains\Platform\Events\QuotationRejected::class => [
            \App\Domains\Platform\Listeners\NotifyQuotationResponse::class . '@handleRejected',
        ],
        \App\Domains\Platform\Events\QuotationReopened::class => [],
        \App\Domains\Platform\Events\QuotationViewed::class   => [],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
