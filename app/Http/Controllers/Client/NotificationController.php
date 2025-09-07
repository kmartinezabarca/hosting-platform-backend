<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * Get user's notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        $notifications = $user->notifications()
            ->when($request->unread_only, function ($query) {
                return $query->whereNull('read_at');
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        $user = Auth::user();
        
        $notification = $user->notifications()->find($notificationId);
        
        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notificación no encontrada.',
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notificación marcada como leída.',
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(): JsonResponse
    {
        $user = Auth::user();
        
        $user->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Todas las notificaciones marcadas como leídas.',
        ]);
    }

    /**
     * Get unread notifications count
     */
    public function getUnreadCount(): JsonResponse
    {
        $user = Auth::user();
        
        $count = $user->unreadNotifications()->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }

    /**
     * Delete notification
     */
    public function destroy(string $notificationId): JsonResponse
    {
        $user = Auth::user();
        
        $notification = $user->notifications()->find($notificationId);
        
        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notificación no encontrada.',
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notificación eliminada.',
        ]);
    }

    /**
     * Get notification preferences
     */
    public function getPreferences(): JsonResponse
    {
        $user = Auth::user();
        
        // Obtener preferencias de notificación del usuario
        // Esto podría estar en una tabla separada o en el modelo User
        $preferences = [
            'email_notifications' => $user->email_notifications ?? true,
            'push_notifications' => $user->push_notifications ?? true,
            'service_updates' => $user->service_notifications ?? true,
            'payment_updates' => $user->payment_notifications ?? true,
            'ticket_updates' => $user->ticket_notifications ?? true,
            'invoice_updates' => $user->invoice_notifications ?? true,
        ];

        return response()->json([
            'success' => true,
            'preferences' => $preferences,
        ]);
    }

    /**
     * Update notification preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        $validated = $request->validate([
            'email_notifications' => 'boolean',
            'push_notifications' => 'boolean',
            'service_updates' => 'boolean',
            'payment_updates' => 'boolean',
            'ticket_updates' => 'boolean',
            'invoice_updates' => 'boolean',
        ]);

        // Actualizar preferencias del usuario
        $user->update([
            'email_notifications' => $validated['email_notifications'] ?? $user->email_notifications,
            'push_notifications' => $validated['push_notifications'] ?? $user->push_notifications,
            'service_notifications' => $validated['service_updates'] ?? $user->service_notifications,
            'payment_notifications' => $validated['payment_updates'] ?? $user->payment_notifications,
            'ticket_notifications' => $validated['ticket_updates'] ?? $user->ticket_notifications,
            'invoice_notifications' => $validated['invoice_updates'] ?? $user->invoice_notifications,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Preferencias de notificación actualizadas.',
            'preferences' => [
                'email_notifications' => $user->email_notifications,
                'push_notifications' => $user->push_notifications,
                'service_updates' => $user->service_notifications,
                'payment_updates' => $user->payment_notifications,
                'ticket_updates' => $user->ticket_notifications,
                'invoice_updates' => $user->invoice_notifications,
            ],
        ]);
    }
}

