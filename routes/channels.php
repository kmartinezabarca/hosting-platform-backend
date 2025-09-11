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

Broadcast::channel('admin.chat.status', function (User $user) {
    return $user->isAdmin();
});

Broadcast::channel('admin.chat', function (User $user) {
    return $user->isAdmin(); // sólo administradores
});

// Canal general para usuarios autenticados
Broadcast::channel('App.Models.User.{uuid}', function ($user, $uuid) {
    return (int) $user->uuid === (int) $uuid;
});

// Canal privado para cada usuario específico
Broadcast::channel('user.{uuid}', function (User $user, $uuid) {
    return (int) $user->uuid === (int) $uuid;
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
    return $user->isAdmin();
});

Broadcast::channel('admin.notifications', function (User $user) {
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

