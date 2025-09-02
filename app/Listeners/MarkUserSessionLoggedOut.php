<?php

namespace App\Listeners;

use App\Models\UserSession;
use Illuminate\Auth\Events\Logout;

class MarkUserSessionLoggedOut
{
    /**
     * Handle the event.
     *
     * @param  \Illuminate\Auth\Events\Logout  $event
     * @return void
     */
    public function handle(Logout $event): void
    {
        $user = $event->user;
        if (! $user) {
            return;
        }

        $sessionId = request()->session()->getId();

        UserSession::where('user_id', $user->id)
            ->where('laravel_session_id', $sessionId)
            ->whereNull('logout_at')
            ->update(['logout_at' => now()]);
    }
}
