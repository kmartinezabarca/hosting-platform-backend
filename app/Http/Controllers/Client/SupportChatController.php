<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SupportChatController extends Controller
{
    /**
     * Obtiene (o crea) el "ticket de soporte activo" del usuario.
     * Consideramos "activos" los estados distintos de resolved/closed.
     */
    public function getSupportRoom(): JsonResponse
    {
        $user = Auth::user();

        $ticket = Ticket::where('user_id', $user->id)
            ->whereIn('status', ['open', 'in_progress', 'waiting_customer'])
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at')
            ->first();

        if (!$ticket) {
            $ticket = Ticket::create([
                'uuid'          => (string) Str::uuid(),
                'user_id'       => $user->id,
                'ticket_number' => $this->generateTicketNumber(),
                'subject'       => 'Chat de Soporte',
                'description'   => null,
                'priority'      => 'medium',
                'status'        => 'open',
                'category'      => 'general',     // ajusta si prefieres otra categoría
                'department'    => 'technical',   // ajusta si prefieres otro depto
                'last_reply_at' => now(),
                'last_reply_by' => $user->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'room'    => $ticket,
                // Canal privado para Echo/Pusher. Usamos ID para no romper el front actual.
                'channel' => 'private-chat.' . $ticket->id,
            ],
        ]);
    }

    /**
     * Lista de "mensajes" = replies del ticket (paginado)
     */
    public function getMessages(Ticket $ticket): JsonResponse
    {
        $user = Auth::user();

        if ((int)$ticket->user_id !== (int)$user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a este ticket.',
            ], 403);
        }

        $messages = TicketReply::with('user:id,first_name,last_name,avatar')
            ->where('ticket_id', $ticket->id)
            ->orderBy('created_at', 'asc')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => $messages,
        ]);
    }

    /**
     * Enviar mensaje (reply) al ticket
     */
    public function sendMessage(Request $request, Ticket $ticket): JsonResponse
    {
        $user = Auth::user();

        if ((int)$ticket->user_id !== (int)$user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a este ticket.',
            ], 403);
        }

        $validated = $request->validate([
            'message'        => 'required|string|max:2000',
            'attachments'    => 'nullable|array',   // si quieres adjuntos en JSON
        ]);

        $reply = TicketReply::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $user->id,
            'message'     => $validated['message'],
            'is_internal' => false,
            'attachments' => $validated['attachments'] ?? null,
        ]);

        // Actualizamos metadata del ticket
        $ticket->update([
            'last_reply_at' => now(),
            'last_reply_by' => $user->id,
            // Si estaba "open" o "waiting_customer" podemos marcarlo en progreso
            'status'        => in_array($ticket->status, ['open', 'waiting_customer']) ? 'in_progress' : $ticket->status,
        ]);

        // Broadcast opcional (ver evento más abajo)
        // event(new \App\Events\TicketMessageSent($ticket, $reply));

        return response()->json([
            'success' => true,
            'data'    => $reply->load('user:id,first_name,last_name,avatar'),
            'message' => 'Mensaje enviado.',
        ]);
    }

    /**
     * "Marcar leído" - no hay read_at en replies. No-op.
     * Dejamos hook por si luego agregas columna/tabla de lecturas.
     */
    public function markAsRead(Ticket $ticket): JsonResponse
    {
        $user = Auth::user();

        if ((int)$ticket->user_id !== (int)$user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a este ticket.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
        ]);
    }

    /**
     * Unread count (heurística por ticket):
     * Tickets activos donde el último en responder NO fue el usuario.
     */
    public function getUnreadCount(): JsonResponse
    {
        $user = Auth::user();

        $count = Ticket::where('user_id', $user->id)
            ->whereIn('status', ['open', 'in_progress', 'waiting_customer'])
            ->whereNotNull('last_reply_by')
            ->where('last_reply_by', '!=', $user->id)
            ->count();

        return response()->json([
            'success'       => true,
            'unread_count'  => $count,
        ]);
    }

    /**
     * Cerrar ticket
     */
    public function closeRoom(Ticket $ticket): JsonResponse
    {
        $user = Auth::user();

        if ((int)$ticket->user_id !== (int)$user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a este ticket.',
            ], 403);
        }

        $ticket->update([
            'status'    => 'closed',
            'closed_at' => now(),
        ]);

        TicketReply::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $user->id,
            'message'     => 'El ticket ha sido cerrado por el cliente.',
            'is_internal' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket cerrado.',
        ]);
    }

    /**
     * Historial de tickets del usuario (último reply primero)
     */
    public function getHistory(): JsonResponse
    {
        $user = Auth::user();

        $tickets = Ticket::where('user_id', $user->id)
            ->with(['latestReply' => function ($q) {
                $q->select('id', 'ticket_id', 'user_id', 'message', 'created_at')
                  ->with('user:id,first_name,last_name,avatar');
            }])
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data'    => $tickets,
        ]);
    }

    private function generateTicketNumber(): string
    {
        // Simple, único y corto. Cambia por tu generador real si ya tienes uno.
        return 'T' . now()->format('YmdHis') . strtoupper(Str::random(4));
    }
}
