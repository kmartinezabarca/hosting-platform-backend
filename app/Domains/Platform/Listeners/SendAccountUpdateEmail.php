<?php

namespace App\Domains\Platform\Listeners;

use App\Domains\Platform\Events\AccountUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendAccountUpdateEmail
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
    public function handle(AccountUpdated $event): void
    {
        //
    }
}
