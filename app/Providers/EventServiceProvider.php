<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Logout;
use App\Listeners\MarkUserSessionLoggedOut;

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
        \App\Events\UserRegistered::class => [
            \App\Listeners\SendWelcomeEmail::class,
        ],
        \App\Events\PasswordResetRequested::class => [
            \App\Listeners\SendPasswordResetEmail::class,
        ],
        \App\Events\PurchaseCompleted::class => [
            \App\Listeners\SendPurchaseConfirmationEmail::class,
        ],
        \App\Events\PaymentProcessed::class => [
            \App\Listeners\SendPaymentSuccessEmail::class,
            \App\Listeners\CreatePaymentNotification::class . '@handleProcessed',
        ],
        \App\Events\InvoiceGenerated::class => [
            \App\Listeners\SendInvoiceGeneratedEmail::class,
        ],
        \App\Events\ServiceNotificationSent::class => [
            \App\Listeners\SendServiceNotificationEmail::class,
        ],
        \App\Events\AccountUpdated::class => [
            \App\Listeners\SendAccountUpdateEmail::class,
        ],

        // Service Events
        \App\Events\ServiceStatusChanged::class => [
            \App\Listeners\CreateServiceNotification::class . '@handleStatusChanged',
        ],
        \App\Events\ServicePurchased::class => [
            \App\Listeners\CreateServiceNotification::class . '@handlePurchased',
        ],
        \App\Events\ServiceReady::class => [
            \App\Listeners\CreateServiceNotification::class . '@handleReady',
        ],
        \App\Events\ServiceMaintenanceScheduled::class => [
            \App\Listeners\CreateServiceNotification::class . '@handleMaintenanceScheduled',
        ],
        \App\Events\ServiceMaintenanceCompleted::class => [
            \App\Listeners\CreateServiceNotification::class . '@handleMaintenanceCompleted',
        ],

        // Payment Events
        \App\Events\PaymentFailed::class => [
            \App\Listeners\CreatePaymentNotification::class . '@handleFailed',
        ],
        \App\Events\AutomaticPaymentProcessed::class => [
            \App\Listeners\CreatePaymentNotification::class . '@handleAutomaticProcessed',
        ],

        // Invoice Events
        \App\Events\InvoiceStatusChanged::class => [
            // Add invoice notification listener here if needed
        ],
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
