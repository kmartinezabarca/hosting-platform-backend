<?php

namespace App\Listeners;

use App\Events\ServiceNotificationSent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendServiceNotificationEmail
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(ServiceNotificationSent $event): void
    {
        //
    }
}
