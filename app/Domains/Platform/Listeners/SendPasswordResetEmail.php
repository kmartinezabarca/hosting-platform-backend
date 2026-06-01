<?php

namespace App\Domains\Platform\Listeners;

use App\Domains\Platform\Events\PasswordResetRequested;
use App\Domains\Platform\Mail\PasswordResetMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendPasswordResetEmail implements ShouldQueue
{
    use InteractsWithQueue;

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
    public function handle(PasswordResetRequested $event): void
    {
        Mail::to($event->user->email)->send(
            new PasswordResetMail($event->user, $event->resetUrl, $event->ipAddress)
        );
    }
}
