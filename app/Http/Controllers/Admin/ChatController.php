<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    /**
     * GET /admin/chat/active-rooms
     * Tickets “activos”: open, in_progress, waiting_customer
     */
    public function getActiveRooms(Request $request): JsonResponse
    {
        $query = Ticket::query()
            ->with([
                'user:id,first_name,last_name,email,avatar',
                'latestReply' => function ($q) {
                    $q->select(
                        'ticket_replies.id',
                        'ticket_replies.ticket_id',   // 👈 prefijado
                        'ticket_replies.user_id',
                        'ticket_replies.message',
                        'ticket_replies.created_at'
                    )->with('user:id,first_name,last_name,avatar');
                },
            ])
            ->whereIn('status', ['open', 'in_progress', 'waiting_customer'])
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at');

        // Búsqueda opcional por asunto / ticket_number / usuario
        if ($search = trim((string)$request->get('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('ticket_number', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($qu) use ($search) {
                      $qu->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Indicador “unread para admin”: 1 si el último en responder fue el cliente
        $query->addSelect([
            'unread_for_admin' => DB::raw('CASE WHEN tickets.last_reply_by = tickets.user_id THEN 1 ELSE 0 END'),
        ]);

        $rooms = $query->paginate((int)($request->get('per_page', 20)));

        return response()->json([
            'success' => true,
            'data'    => $rooms,
        ]);
    }

    /**
     * GET /admin/chat/all-rooms
     * Todos los tickets con filtros opcionales
     */
    public function getAllRooms(Request $request): JsonResponse
    {
        $query = Ticket::query()
            ->with([
                'user:id,first_name,last_name,email,avatar',
                'latestReply' => function ($q) {
                    $q->select(
                        'ticket_replies.id',
                        'ticket_replies.ticket_id',   // 👈 prefijado
                        'ticket_replies.user_id',
                        'ticket_replies.message',
                        'ticket_replies.created_at'
                    )->with('user:id,first_name,last_name,avatar');
                },
            ])
            ->when($request->filled('status'), function ($q) use ($request) {
                // status: open | in_progress | waiting_customer | resolved | closed
                $q->where('status', $request->get('status'));
            })
            ->when($request->filled('priority'), function ($q) use ($request) {
                $q->where('priority', $request->get('priority'));
            })
            ->when($request->filled('category'), function ($q) use ($request) {
                $q->where('category', $request->get('category'));
            })
            ->when($request->filled('assigned'), function ($q) use ($request) {
                // assigned = "1" -> solo asignados, "0" -> solo sin asignar
                if ($request->get('assigned') === '1') $q->whereNotNull('assigned_to');
                if ($request->get('assigned') === '0') $q->whereNull('assigned_to');
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim((string)$request->get('search'));
                $q->where(function ($qq) use ($search) {
                    $qq->where('subject', 'like', "%{$search}%")
                       ->orWhere('ticket_number', 'like', "%{$search}%")
                       ->orWhereHas('user', function ($qu) use ($search) {
                           $qu->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%");
                       });
                });
            })
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at');

        $query->addSelect([
            'unread_for_admin' => DB::raw('CASE WHEN tickets.last_reply_by = tickets.user_id THEN 1 ELSE 0 END'),
        ]);

        $rooms = $query->paginate((int)($request->get('per_page', 20)));

        return response()->json([
            'success' => true,
            'data'    => $rooms,
        ]);
    }

    /**
     * GET /admin/chat/{ticket}/messages
     */
    public function getMessages(Ticket $ticket): JsonResponse
    {
        $messages = TicketReply::with('user:id,first_name,last_name,avatar')
            ->where('ticket_id', $ticket->id)
            ->orderBy('created_at', 'asc')
            ->paginate(50);

        // No hay read_at en ticket_replies -> no marcamos leído aquí.
        return response()->json([
            'success' => true,
            'data'    => $messages,
        ]);
    }

    /**
     * POST /admin/chat/{ticket}/messages
     * Enviar mensaje como admin/agent
     */
    public function sendMessage(Request $request, Ticket $ticket): JsonResponse
    {
        $admin = Auth::user();

        $validated = $request->validate([
            'message'     => 'required|string|max:2000',
            'attachments' => 'nullable|array',
        ]);

        $reply = TicketReply::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $admin->id,
            'message'     => $validated['message'],
            'is_internal' => false,
            'attachments' => $validated['attachments'] ?? null,
        ]);

        $ticket->update([
            'last_reply_at' => now(),
            'last_reply_by' => $admin->id,
            // Tras responder el agente, típicamente queda esperando al cliente:
            'status'        => 'waiting_customer',
        ]);

        // Broadcast del evento
        event(new \App\Events\TicketReplied($ticket, $reply));

        return response()->json([
            'success' => true,
            'data'    => $reply->load('user:id,first_name,last_name,avatar'),
            'message' => 'Mensaje enviado.',
        ]);
    }

    /**
     * PUT /admin/chat/{ticket}/assign
     */
    public function assignToAgent(Request $request, Ticket $ticket): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:users,id',
        ]);

        $agent = User::findOrFail($validated['agent_id']);

        $ticket->update([
            'assigned_to' => $agent->id,
            'status'      => $ticket->status === 'open' ? 'in_progress' : $ticket->status,
        ]);

        TicketReply::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $agent->id,
            'message'     => "Ticket asignado a {$agent->first_name} {$agent->last_name}.",
            'is_internal' => true, // mensaje de sistema interno
        ]);

        return response()->json([
            'success' => true,
            'message' => "Ticket asignado a {$agent->first_name} {$agent->last_name}.",
        ]);
    }

    /**
     * PUT /admin/chat/{ticket}/close
     */
    public function closeRoom(Ticket $ticket): JsonResponse
    {
        $admin = Auth::user();

        $ticket->update([
            'status'    => 'closed',
            'closed_at' => now(),
            'last_reply_at' => now(),
            'last_reply_by' => $admin->id,
        ]);

        TicketReply::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $admin->id,
            'message'     => "El ticket ha sido cerrado por {$admin->first_name} {$admin->last_name}.",
            'is_internal' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket cerrado.',
        ]);
    }

    /**
     * PUT /admin/chat/{ticket}/reopen
     */
    public function reopenRoom(Ticket $ticket): JsonResponse
    {
        $admin = Auth::user();

        $ticket->update([
            'status'       => 'open',
            'closed_at'    => null,
            'last_reply_at'=> now(),
            'last_reply_by'=> $admin->id,
        ]);

        TicketReply::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $admin->id,
            'message'     => "El ticket ha sido reabierto por {$admin->first_name} {$admin->last_name}.",
            'is_internal' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket reabierto.',
        ]);
    }

    /**
     * GET /admin/chat/stats
     */
    public function getStats(): JsonResponse
    {
        $activeStatuses = ['open','in_progress','waiting_customer'];

        $stats = [
            'active_chats'      => Ticket::whereIn('status', $activeStatuses)->count(),
            'total_chats'       => Ticket::count(),
            'unassigned_chats'  => Ticket::whereIn('status', $activeStatuses)->whereNull('assigned_to')->count(),
            'today_chats'       => Ticket::whereDate('created_at', today())->count(),
            // Heurística: tickets activos donde el último en responder fue el cliente
            'awaiting_admin'    => Ticket::whereIn('status', $activeStatuses)
                                        ->whereColumn('last_reply_by', 'user_id')
                                        ->count(),
            'avg_response_time' => 0.0, // TODO si más adelante guardas métricas de respuesta
        ];

        // Chats por agente (solo conteo)
        $chatsByAgent = Ticket::select('assigned_to', DB::raw('COUNT(*) as count'))
            ->whereNotNull('assigned_to')
            ->groupBy('assigned_to')
            ->get()
            ->map(function ($row) {
                $agent = User::select('id','first_name','last_name')->find($row->assigned_to);
                return [
                    'agent_id'   => $row->assigned_to,
                    'agent_name' => $agent ? "{$agent->first_name} {$agent->last_name}" : 'N/A',
                    'count'      => (int)$row->count,
                ];
            });

        // Actividad por día últimos 7 días
        $dailyActivity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date  = now()->subDays($i);
            $count = Ticket::whereDate('created_at', $date)->count();
            $dailyActivity[] = [
                'date'  => $date->format('Y-m-d'),
                'count' => $count,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'stats'          => $stats,
                'chats_by_agent' => $chatsByAgent,
                'daily_activity' => $dailyActivity,
            ],
        ]);
    }

    /**
     * GET /admin/chat/unread-count
     * Heurística: tickets activos cuyo último en responder fue el cliente.
     */
    public function getUnreadCount(): JsonResponse
    {
        $activeStatuses = ['open','in_progress','waiting_customer'];

        $count = Ticket::whereIn('status', $activeStatuses)
            ->whereColumn('last_reply_by', 'user_id')
            ->count();

        return response()->json([
            'success'      => true,
            'unread_count' => $count,
        ]);
    }
}
