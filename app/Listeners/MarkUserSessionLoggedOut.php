<?php

namespace App\Listeners;

use App\Models\UserSession;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

class MarkUserSessionLoggedOut
{
    /**
     * La instancia de la petición actual.
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * Crea una nueva instancia del listener.
     * Inyectamos la Request para poder acceder a las cookies.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

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

        $deviceToken = $this->request->cookie('device_token');

        if (!$deviceToken) {
            return;
        }

        // $sessionId = request()->session()->getId();

        UserSession::where('user_id', $user->id)
            ->where('device_token', $deviceToken)
            ->whereNull('logout_at')
            ->update(['logout_at' => now()]);

        cookie()->queue(cookie()->forget('device_token'));
    }
}
