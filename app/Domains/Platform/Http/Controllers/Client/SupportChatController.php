<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Domains\Platform\Events\TicketRead;
use App\Domains\Platform\Events\TicketReplyReceiptUpdated;
use App\Domains\Platform\Events\TicketTyping;
use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\Ticket;
use App\Domains\Platform\Models\TicketReply;
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

        // Al abrir la conversación, el cliente "lee" los mensajes del staff.
        $this->markStaffRepliesAsRead($ticket);

        $messages = TicketReply::with('user:id,uuid,first_name,last_name,email,avatar_url,role')
            ->where('ticket_id', $ticket->id)
            ->orderBy('created_at', 'asc')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => $messages,
        ]);
    }

    /**
     * Marca como leídas las respuestas del staff (entrantes para el cliente)
     * que aún no tienen read_at en el ticket dado.
     */
    private function markStaffRepliesAsRead(Ticket $ticket): void
    {
        $user = Auth::user();
        $now  = now();

        $unreadReplies = TicketReply::where('ticket_id', $ticket->id)
            ->where('is_internal', false)
            ->whereNull('read_at')
            ->where('user_id', '!=', $ticket->user_id) // del staff
            ->get();

        if ($unreadReplies->isEmpty()) {
            return;
        }

        foreach ($unreadReplies as $reply) {
            $patch = ['read_at' => $now];
            // Si todavía no fue marcado como entregado, lo cubrimos en el mismo update.
            if (!$reply->delivered_at) {
                $patch['delivered_at'] = $now;
            }
            $reply->forceFill($patch)->save();

            // Receipt en vivo (✓✓) en el canal presence del ticket — el admin
            // ve sus propios mensajes marcados como leídos sin polling.
            broadcast(new TicketReplyReceiptUpdated($reply, 'read', $user->id));
        }

        // Compat: evento legacy que ya escuchaban algunas pantallas admin.
        TicketRead::dispatch($ticket);
    }

    /**
     * Marca un reply individual como ENTREGADO al usuario actual.
     * Se llama cuando el frontend recibe el mensaje vía WebSocket y aún no lo
     * tenía persistido como entregado. Idempotente: no re-emite si ya estaba.
     */
    public function markReplyDelivered(Ticket $ticket, TicketReply $reply): JsonResponse
    {
        $user = Auth::user();

        if ((int) $ticket->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Sin acceso.'], 403);
        }
        if ((int) $reply->ticket_id !== (int) $ticket->id) {
            return response()->json(['success' => false, 'message' => 'Reply no pertenece al ticket.'], 422);
        }
        // El usuario no marca como "entregadas" sus propias respuestas.
        if ((int) $reply->user_id === (int) $user->id) {
            return response()->json(['success' => true, 'data' => $reply]);
        }

        if (!$reply->delivered_at) {
            $reply->forceFill(['delivered_at' => now()])->save();
            broadcast(new TicketReplyReceiptUpdated($reply, 'delivered', $user->id));
        }

        return response()->json(['success' => true, 'data' => $reply]);
    }

    /**
     * Marca un reply individual como LEÍDO por el usuario actual.
     * El frontend lo dispara cuando el mensaje queda visible en el viewport
     * con la ventana enfocada. Sólo aplica a replies del staff (entrantes).
     */
    public function markReplyRead(Ticket $ticket, TicketReply $reply): JsonResponse
    {
        $user = Auth::user();

        if ((int) $ticket->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Sin acceso.'], 403);
        }
        if ((int) $reply->ticket_id !== (int) $ticket->id) {
            return response()->json(['success' => false, 'message' => 'Reply no pertenece al ticket.'], 422);
        }
        if ((int) $reply->user_id === (int) $user->id) {
            return response()->json(['success' => true, 'data' => $reply]);
        }

        $patch = [];
        if (!$reply->delivered_at) $patch['delivered_at'] = now();
        if (!$reply->read_at)      $patch['read_at']      = now();

        if (!empty($patch)) {
            $reply->forceFill($patch)->save();
            broadcast(new TicketReplyReceiptUpdated($reply, 'read', $user->id));
        }

        return response()->json(['success' => true, 'data' => $reply]);
    }

    /**
     * Señal de "escribiendo…" del cliente hacia el staff.
     * El frontend la dispara (con debounce) al teclear y al dejar de teclear.
     * Body: { is_typing: bool } — por defecto true.
     */
    public function typing(Request $request, Ticket $ticket): JsonResponse
    {
        $user = Auth::user();

        if ((int) $ticket->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Sin acceso.'], 403);
        }

        $isTyping = $request->boolean('is_typing', true);

        // ->toOthers() evita que el propio autor reciba el eco de su typing.
        broadcast(new TicketTyping($ticket, $user, $isTyping))->toOthers();

        return response()->json(['success' => true]);
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
            'message'        => 'required_without:attachments|nullable|string|max:2000',
            'attachments'    => 'nullable|array|max:5',
            'attachments.*'  => 'file|max:20480|mimes:jpg,jpeg,png,webp,gif,pdf,txt,zip',
        ]);

        $stored = $this->storeAttachments($request, $ticket);

        $reply = TicketReply::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $user->id,
            'message'     => $validated['message'] ?? '',
            'is_internal' => false,
            'attachments' => $stored ?: null,
        ]);

        // Actualizamos metadata del ticket
        $ticket->update([
            'last_reply_at' => now(),
            'last_reply_by' => $user->id,
            // Si estaba "open" o "waiting_customer" podemos marcarlo en progreso
            'status'        => in_array($ticket->status, ['open', 'waiting_customer']) ? 'in_progress' : $ticket->status,
        ]);

        $reply->load('user:id,uuid,first_name,last_name,email,avatar_url,role');

        // Broadcast del evento (con el reply completo + adjuntos)
        event(new \App\Domains\Platform\Events\TicketReplied($ticket, $reply));

        return response()->json([
            'success' => true,
            'data'    => $reply,
            'message' => 'Mensaje enviado.',
        ]);
    }

    /**
     * Marca como leídas las respuestas del staff en el ticket.
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

        $this->markStaffRepliesAsRead($ticket);

        return response()->json([
            'success' => true,
            'message' => 'OK',
        ]);
    }

    /**
     * Unread real: respuestas del staff (no internas) sin leer en
     * tickets activos del usuario.
     */
    public function getUnreadCount(): JsonResponse
    {
        $user = Auth::user();

        $count = TicketReply::query()
            ->join('tickets', 'tickets.id', '=', 'ticket_replies.ticket_id')
            ->where('ticket_replies.is_internal', false)
            ->whereNull('ticket_replies.read_at')
            ->where('tickets.user_id', $user->id)
            ->whereIn('tickets.status', ['open', 'in_progress', 'waiting_customer'])
            // Solo respuestas del staff (entrantes para el cliente)
            ->whereColumn('ticket_replies.user_id', '!=', 'tickets.user_id')
            ->count('ticket_replies.id');

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

        event(new \App\Domains\Platform\Events\TicketClosed($ticket->fresh(), $user));

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
                $q->select('id', 'uuid', 'ticket_id', 'user_id', 'message', 'delivered_at', 'read_at', 'created_at')
                  ->with('user:id,uuid,first_name,last_name,email,avatar_url,role');
            }])
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data'    => $tickets,
        ]);
    }

    /**
     * Guarda los archivos adjuntos subidos en el disco público y devuelve
     * el arreglo [{path, name, mime, size}] que se persiste en el reply.
     * El accessor de TicketReply añade automáticamente la `url` completa.
     */
    private function storeAttachments(Request $request, Ticket $ticket): array
    {
        if (!$request->hasFile('attachments')) {
            return [];
        }

        $stored = [];
        foreach ($request->file('attachments') as $file) {
            if (!$file->isValid()) {
                continue;
            }
            $path = $file->store('chat-attachments/' . $ticket->id, 'public');
            $stored[] = [
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ];
        }

        return $stored;
    }

    private function generateTicketNumber(): string
    {
        // Simple, único y corto. Cambia por tu generador real si ya tienes uno.
        return 'T' . now()->format('YmdHis') . strtoupper(Str::random(4));
    }
}
