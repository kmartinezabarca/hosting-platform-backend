<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatRoom;
use App\Models\ChatMessage;
use App\Models\User;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    /**
     * Get all active chat rooms
     */
    public function getActiveRooms(): JsonResponse
    {
        $chatRooms = ChatRoom::where('status', 'active')
            ->with([
                'user:id,name,email,avatar',
                'messages' => function ($query) {
                    $query->latest()->limit(1);
                }
            ])
            ->withCount([
                'messages as unread_count' => function ($query) {
                    $query->where('is_from_admin', false)
                          ->whereNull('read_at');
                }
            ])
            ->orderBy('last_message_at', 'desc')
            ->paginate(20);
        
        return response()->json([
            'success' => true,
            'data' => $chatRooms,
        ]);
    }

    /**
     * Get all chat rooms (active and closed)
     */
    public function getAllRooms(Request $request): JsonResponse
    {
        $chatRooms = ChatRoom::query()
            ->with([
                'user:id,name,email,avatar',
                'messages' => function ($query) {
                    $query->latest()->limit(1);
                }
            ])
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->when($request->search, function ($query, $search) {
                return $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('last_message_at', 'desc')
            ->paginate(20);
        
        return response()->json([
            'success' => true,
            'data' => $chatRooms,
        ]);
    }

    /**
     * Get chat messages
     */
    public function getMessages(ChatRoom $chatRoom): JsonResponse
    {
        $messages = $chatRoom->messages()
            ->with('user:id,name,avatar')
            ->orderBy('created_at', 'asc')
            ->paginate(50);
        
        // Marcar mensajes del cliente como leídos
        $chatRoom->messages()
            ->where('is_from_admin', false)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        
        return response()->json([
            'success' => true,
            'data' => $messages,
        ]);
    }

    /**
     * Send message as admin
     */
    public function sendMessage(Request $request, ChatRoom $chatRoom): JsonResponse
    {
        $admin = Auth::user();
        
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'type' => 'in:text,image,file',
            'attachment_url' => 'nullable|string',
        ]);
        
        $message = $chatRoom->messages()->create([
            'user_id' => $admin->id,
            'message' => $validated['message'],
            'type' => $validated['type'] ?? 'text',
            'attachment_url' => $validated['attachment_url'] ?? null,
            'is_from_admin' => true,
        ]);
        
        // Actualizar última actividad de la sala
        $chatRoom->update([
            'last_message_at' => now(),
            'last_message' => $validated['message'],
            'status' => 'active', // Reactivar si estaba cerrada
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
     * Assign chat room to agent
     */
    public function assignToAgent(Request $request, ChatRoom $chatRoom): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:users,id',
        ]);
        
        $agent = User::find($validated['agent_id']);
        
        $chatRoom->update([
            'assigned_to' => $agent->id,
        ]);
        
        // Enviar mensaje de sistema
        $chatRoom->messages()->create([
            'user_id' => null,
            'message' => "Chat asignado a {$agent->name}.",
            'type' => 'system',
            'is_from_admin' => true,
        ]);
        
        return response()->json([
            'success' => true,
            'message' => "Chat asignado a {$agent->name}.",
        ]);
    }

    /**
     * Close chat room
     */
    public function closeRoom(ChatRoom $chatRoom): JsonResponse
    {
        $admin = Auth::user();
        
        $chatRoom->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $admin->id,
        ]);
        
        // Enviar mensaje de sistema
        $chatRoom->messages()->create([
            'user_id' => null,
            'message' => "El chat ha sido cerrado por {$admin->name}.",
            'type' => 'system',
            'is_from_admin' => true,
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Chat cerrado.',
        ]);
    }

    /**
     * Reopen chat room
     */
    public function reopenRoom(ChatRoom $chatRoom): JsonResponse
    {
        $admin = Auth::user();
        
        $chatRoom->update([
            'status' => 'active',
            'closed_at' => null,
            'closed_by' => null,
        ]);
        
        // Enviar mensaje de sistema
        $chatRoom->messages()->create([
            'user_id' => null,
            'message' => "El chat ha sido reabierto por {$admin->name}.",
            'type' => 'system',
            'is_from_admin' => true,
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Chat reabierto.',
        ]);
    }

    /**
     * Get chat statistics
     */
    public function getStats(): JsonResponse
    {
        $stats = [
            'active_chats' => ChatRoom::where('status', 'active')->count(),
            'total_chats' => ChatRoom::count(),
            'unassigned_chats' => ChatRoom::where('status', 'active')
                ->whereNull('assigned_to')
                ->count(),
            'today_chats' => ChatRoom::whereDate('created_at', today())->count(),
            'avg_response_time' => $this->getAverageResponseTime(),
        ];
        
        // Chats por agente
        $chatsByAgent = ChatRoom::where('assigned_to', '!=', null)
            ->with('assignedAgent:id,name')
            ->selectRaw('assigned_to, COUNT(*) as count')
            ->groupBy('assigned_to')
            ->get();
        
        // Actividad por día en los últimos 7 días
        $dailyActivity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $count = ChatRoom::whereDate('created_at', $date)->count();
            
            $dailyActivity[] = [
                'date' => $date->format('Y-m-d'),
                'count' => $count,
            ];
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'chats_by_agent' => $chatsByAgent,
                'daily_activity' => $dailyActivity,
            ],
        ]);
    }

    /**
     * Get unread messages count for admin
     */
    public function getUnreadCount(): JsonResponse
    {
        $count = ChatMessage::where('is_from_admin', false)
            ->whereNull('read_at')
            ->whereHas('chatRoom', function ($query) {
                $query->where('status', 'active');
            })
            ->count();
        
        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }

    /**
     * Calculate average response time
     */
    private function getAverageResponseTime(): float
    {
        // Implementar lógica para calcular tiempo promedio de respuesta
        // Esto requeriría un análisis más complejo de los mensajes
        return 0.0; // Placeholder
    }
}

