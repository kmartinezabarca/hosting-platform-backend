<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ChatRoom;
use App\Models\ChatMessage;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    /**
     * Get or create chat room for support
     */
    public function getSupportRoom(): JsonResponse
    {
        $user = Auth::user();
        
        // Buscar sala de chat activa para el usuario
        $chatRoom = ChatRoom::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
        
        // Si no existe, crear una nueva
        if (!$chatRoom) {
            $chatRoom = ChatRoom::create([
                'uuid' => Str::uuid(),
                'user_id' => $user->id,
                'type' => 'support',
                'status' => 'active',
                'title' => 'Chat de Soporte - ' . $user->name,
            ]);
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'room' => $chatRoom,
                'channel' => 'chat.' . $chatRoom->uuid,
            ],
        ]);
    }

    /**
     * Get chat messages
     */
    public function getMessages(ChatRoom $chatRoom): JsonResponse
    {
        $user = Auth::user();
        
        // Verificar que el usuario tenga acceso a esta sala
        if ($chatRoom->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta sala de chat.',
            ], 403);
        }
        
        $messages = $chatRoom->messages()
            ->with('user:id,name,avatar')
            ->orderBy('created_at', 'asc')
            ->paginate(50);
        
        // Marcar mensajes como leídos
        $chatRoom->messages()
            ->where('user_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        
        return response()->json([
            'success' => true,
            'data' => $messages,
        ]);
    }

    /**
     * Send message
     */
    public function sendMessage(Request $request, ChatRoom $chatRoom): JsonResponse
    {
        $user = Auth::user();
        
        // Verificar que el usuario tenga acceso a esta sala
        if ($chatRoom->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta sala de chat.',
            ], 403);
        }
        
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'type' => 'in:text,image,file',
            'attachment_url' => 'nullable|string',
        ]);
        
        $message = $chatRoom->messages()->create([
            'user_id' => $user->id,
            'message' => $validated['message'],
            'type' => $validated['type'] ?? 'text',
            'attachment_url' => $validated['attachment_url'] ?? null,
            'is_from_admin' => false,
        ]);
        
        // Actualizar última actividad de la sala
        $chatRoom->update([
            'last_message_at' => now(),
            'last_message' => $validated['message'],
        ]);
        
        // Cargar relaciones para el broadcast
        $message->load('user:id,name,avatar');
        
        // Broadcast del mensaje
        broadcast(new MessageSent($chatRoom, $message))->toOthers();
        
        return response()->json([
            'success' => true,
            'data' => $message,
            'message' => 'Mensaje enviado.',
        ]);
    }

    /**
     * Mark messages as read
     */
    public function markAsRead(ChatRoom $chatRoom): JsonResponse
    {
        $user = Auth::user();
        
        // Verificar que el usuario tenga acceso a esta sala
        if ($chatRoom->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta sala de chat.',
            ], 403);
        }
        
        $chatRoom->messages()
            ->where('user_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        
        return response()->json([
            'success' => true,
            'message' => 'Mensajes marcados como leídos.',
        ]);
    }

    /**
     * Get unread messages count
     */
    public function getUnreadCount(): JsonResponse
    {
        $user = Auth::user();
        
        $count = ChatMessage::whereHas('chatRoom', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
        ->where('user_id', '!=', $user->id)
        ->whereNull('read_at')
        ->count();
        
        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }

    /**
     * Close chat room
     */
    public function closeRoom(ChatRoom $chatRoom): JsonResponse
    {
        $user = Auth::user();
        
        // Verificar que el usuario tenga acceso a esta sala
        if ($chatRoom->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta sala de chat.',
            ], 403);
        }
        
        $chatRoom->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        
        // Enviar mensaje de sistema
        $chatRoom->messages()->create([
            'user_id' => null,
            'message' => 'El chat ha sido cerrado por el cliente.',
            'type' => 'system',
            'is_from_admin' => false,
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Chat cerrado.',
        ]);
    }

    /**
     * Get chat history
     */
    public function getHistory(): JsonResponse
    {
        $user = Auth::user();
        
        $chatRooms = ChatRoom::where('user_id', $user->id)
            ->with(['messages' => function ($query) {
                $query->latest()->limit(1);
            }])
            ->orderBy('last_message_at', 'desc')
            ->paginate(10);
        
        return response()->json([
            'success' => true,
            'data' => $chatRooms,
        ]);
    }
}

