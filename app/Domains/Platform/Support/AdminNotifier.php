<?php

namespace App\Domains\Platform\Support;

use App\Domains\Platform\Notifications\ServiceNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Punto único para avisar al panel de administración de la plataforma.
 *
 * Persiste la notificación (data->target = 'admin') y la emite por el canal
 * privado 'admin.notifications' usando ServiceNotification, que NO es ShouldQueue,
 * por lo que funciona aun sin worker de cola. No toca las notificaciones del
 * cliente: úsese ADEMÁS del aviso al cliente, no en su lugar.
 *
 * Nota: esto es exclusivo del dominio Platform. ROKE PET tiene su propio sistema
 * de notificaciones (notification_logs / inbox_notifications) y no debe mezclarse.
 */
class AdminNotifier
{
    /**
     * @param array $opts Opcional: ['email' => bool, 'action_url' => string,
     *                    'action_text' => string, 'subtitle' => string].
     *                    Con 'email' => true el aviso se envía también por correo
     *                    con la plantilla oficial (logo y branding de Roke).
     */
    public static function notify(string $title, string $message, string $type, array $data = [], array $opts = []): void
    {
        self::dispatch(['admin', 'super_admin'], $title, $message, $type, $data, $opts);
    }

    /**
     * Como notify(), pero incluye también al rol 'support' (p. ej. tickets).
     * El registro en BD se entrega a todo el staff; el broadcast en tiempo real
     * por 'admin.notifications' solo llega a admin/super_admin (auth del canal).
     */
    public static function notifyStaff(string $title, string $message, string $type, array $data = [], array $opts = []): void
    {
        self::dispatch(['admin', 'super_admin', 'support'], $title, $message, $type, $data, $opts);
    }

    private static function dispatch(array $roles, string $title, string $message, string $type, array $data, array $opts = []): void
    {
        $payload = [
            'title'    => $title,
            'message'  => $message,
            'type'     => $type,
            'data'     => $data,
            'target'   => 'admin',
            '_channel' => 'admin.notifications',
        ];

        if (! empty($opts['email']))       { $payload['_email'] = true; }
        if (! empty($opts['action_url']))  { $payload['action_url'] = $opts['action_url']; }
        if (! empty($opts['action_text'])) { $payload['action_text'] = $opts['action_text']; }
        if (! empty($opts['subtitle']))    { $payload['email_subtitle'] = $opts['subtitle']; }

        try {
            User::whereIn('role', $roles)
                ->get()
                ->each(fn (User $admin) => $admin->notify(new ServiceNotification($payload)));
        } catch (\Throwable $e) {
            // Avisar al admin nunca debe romper el flujo de negocio que lo origina.
            Log::error('AdminNotifier: no se pudo notificar a los administradores', [
                'title' => $title,
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
