<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Canal general para usuarios autenticados
Broadcast::channel('App.Models.User.{uuid}', function (User $user, $uuid) {
    return $user->uuid === $uuid;
});

// Canal privado para cada usuario específico
Broadcast::channel('user.{uuid}', function (User $user, $uuid) {
    return $user->uuid === $uuid;
});

// Canal privado por ticket (chat de soporte en tiempo real)
Broadcast::channel('ticket.{uuid}', function (User $user, $uuid) {
    // El cliente dueño del ticket o cualquier staff (admin/super_admin/support)
    if ($user->isStaff()) {
        return [
            'id'         => $user->uuid,
            'user_id'    => $user->id,
            'name'       => $user->full_name,
            'email'      => $user->email,
            'role'       => $user->role,
            'avatar_url' => $user->avatar_full_url,
            'is_staff'   => true,
        ];
    }

    $ticket = \App\Domains\Platform\Models\Ticket::where('uuid', $uuid)->first();
    if ($ticket && (int) $ticket->user_id === (int) $user->id) {
        return [
            'id'         => $user->uuid,
            'user_id'    => $user->id,
            'name'       => $user->full_name,
            'email'      => $user->email,
            'role'       => $user->role,
            'avatar_url' => $user->avatar_full_url,
            'is_staff'   => false,
        ];
    }

    return false;
});

// Canales administrativos - solo para administradores
Broadcast::channel('admin.services', function (User $user) {
    return $user->isAdmin();
});

Broadcast::channel('admin.payments', function (User $user) {
    return $user->isAdmin();
});

Broadcast::channel('admin.invoices', function (User $user) {
    return $user->isAdmin();
});

Broadcast::channel('admin.maintenance', function (User $user) {
    return $user->isAdmin();
});

Broadcast::channel('admin.users', function (User $user) {
    return $user->isAdmin();
});

Broadcast::channel('admin.tickets', function (User $user) {
    // Support también atiende tickets, por lo que recibe el feed de tickets.
    return $user->isStaff();
});

Broadcast::channel('admin.notifications', function (User $user) {
    return $user->isAdmin();
});

Broadcast::channel('admin.backups', function (User $user) {
    return $user->isAdmin();
});

Broadcast::channel('admin.pet.notifications', function (User $user) {
    return $user->isAdmin();
});

// Canal para super administradores
Broadcast::channel('admin.super', function (User $user) {
    return $user->isSuperAdmin();
});

// Canales de presencia para chat en tiempo real
Broadcast::channel('chat.{roomId}', function (User $user, $roomId) {
    // Verificar si el usuario tiene acceso a esta sala de chat
    return [
        'id' => $user->uuid,
        'name' => $user->full_name,
        'avatar' => $user->avatar_full_url,
        'role' => $user->role,
    ];
});

// Canal de presencia para administradores en línea
Broadcast::channel('admin.online', function (User $user) {
    if ($user->isAdmin()) {
        return [
            'id' => $user->uuid,
            'name' => $user->full_name,
            'role' => $user->role,
        ];
    }
});

// Canal para notificaciones de sistema
Broadcast::channel('system.notifications', function (User $user) {
    return true; // Todos los usuarios autenticados pueden recibir notificaciones del sistema
});

// Canal para notificaciones de mantenimiento programado
Broadcast::channel('system.maintenance', function (User $user) {
    return true; // Todos los usuarios autenticados pueden recibir notificaciones de mantenimiento
});

// Canal privado del dueño de mascota (ROKE PET) — escaneos en tiempo real vía Reverb.
// El guard sanctum resuelve al modelo Owner dueño del token; solo autorizamos su canal.
Broadcast::channel('rp-owner.{ownerId}', function ($user, string $ownerId) {
    return $user instanceof \App\Domains\Pet\Models\Owner && $user->uuid === $ownerId;
});

// Canal privado por game server — recibe ping en tiempo real vía Reverb
// El scheduler CollectGameServerPings hace broadcast en este canal cada 5 min.
Broadcast::channel('game-server.{serviceUuid}', function (User $user, string $serviceUuid) {
    $service = \App\Domains\Platform\Models\Service::where('uuid', $serviceUuid)->first();
    return $service && (int) $service->user_id === (int) $user->id;
});

// ── Chat de soporte ROKE PET ──────────────────────────────────────────────────
// El guard de Pet (sanctum->web) resuelve a App\Models\User; el id del dueño en
// el dominio Pet es su `uuid` (== owners.id). Por eso aquí se autoriza por
// uuid/AppAdmin, igual que el resto del dominio Pet (ver EnsurePetAppAdmin).
//
// Canal privado por conversación: lo escucha el DUEÑO propietario o un ADMIN de
// Pet. Un dueño nunca entra a la conversación de otro, ni a las de ROKE
// Industries (que viven en otro sistema/canal).
Broadcast::channel('rp-chat.{conversationId}', function (User $user, string $conversationId) {
    $conversation = \App\Domains\Pet\Models\ChatConversation::find($conversationId);
    if (! $conversation) {
        return false;
    }

    $isPetAdmin = \App\Domains\Pet\Models\AppAdmin::where('user_id', $user->uuid)->exists();

    if ($isPetAdmin) {
        return ['id' => $user->uuid, 'name' => $user->full_name, 'is_staff' => true];
    }

    if ($conversation->owner_id === $user->uuid) {
        return ['id' => $user->uuid, 'name' => $user->full_name, 'is_staff' => false];
    }

    return false;
});

// Feed de administración del chat de Pet — sólo admins de Pet. Recibe mensajes
// nuevos, escalamientos y cambios de estado para la lista en vivo.
Broadcast::channel('rp-admin.chat', function (User $user) {
    return \App\Domains\Pet\Models\AppAdmin::where('user_id', $user->uuid)->exists();
});
