<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use App\Domains\Platform\Models\Agent;
use App\Domains\Platform\Models\Ticket;
use App\Domains\Platform\Models\TicketReply;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    /**
     * Get user's tickets
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->validate([
                'status'     => ['sometimes', 'string', 'in:open,in_progress,waiting_customer,resolved,closed'],
                'priority'   => ['sometimes', 'string', 'in:low,medium,high,urgent'],
                'department' => ['sometimes', 'string', 'max:100'],
                'per_page'   => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $user  = Auth::user();
            $query = Ticket::where('user_id', $user->id);

            if (! empty($filters['status']))     $query->where('status',     $filters['status']);
            if (! empty($filters['priority']))   $query->where('priority',   $filters['priority']);
            if (! empty($filters['department'])) $query->where('department', $filters['department']);

            $tickets = $query
                           ->with(['assignedTo', 'service', 'lastReply.user'])
                           ->withCount('replies')
                           ->orderBy('created_at', 'desc')
                           ->paginate((int) ($filters['per_page'] ?? 15));

            $tickets->getCollection()->transform(function ($t) {
                $t->replies_total = (int) ($t->replies_count ?? 0);

                $t->last_message = $t->lastReply ? [
                    'id'         => $t->lastReply->id,
                    'message'    => $t->lastReply->message,
                    'created_at' => optional($t->lastReply->created_at)->toISOString(),
                    'user'       => $t->lastReply->user ? [
                        'id'   => $t->lastReply->user->id,
                        'name' => $t->lastReply->user->name,
                    ] : null,
                ] : null;

                // (Opcional) ocultar campos auxiliares
                unset($t->replies_count, $t->lastReply);

                return $t;
            });

            return response()->json([
                'success' => true,
                'data' => $tickets
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving tickets',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get a specific ticket with replies
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $user = Auth::user();
            $ticket = Ticket::where('uuid', $uuid)
                          ->where('user_id', $user->id)
                          ->with(['assignedTo', 'service', 'replies.user'])
                          ->first();

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $ticket
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving ticket',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Create a new ticket
     */
public function store(Request $request): JsonResponse
{
    $user = Auth::user();

    // Validaciones con mensajes personalizados
    $validator = Validator::make(
        $request->all(),
        [
            'subject'     => 'required|string|max:500',
            'message'     => 'required|string',
            'priority'    => 'required|in:low,medium,high,urgent',
            'department'  => 'required|in:technical,billing,sales,abuse',
            'service_id'  => [
                'nullable',
                Rule::exists('services', 'id')->where(fn ($query) => $query->where('user_id', $user->id)),
            ],
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,gif,pdf,zip,txt|max:20480',
        ],
        [
            'subject.required'    => 'Indica un asunto para tu solicitud.',
            'subject.max'         => 'El asunto no puede exceder 500 caracteres.',
            'message.required'    => 'Escribe el detalle de tu solicitud.',
            'priority.required'   => 'Selecciona una prioridad.',
            'priority.in'         => 'La prioridad seleccionada no es válida.',
            'department.required' => 'Selecciona un departamento.',
            'department.in'       => 'El departamento seleccionado no es válido.',
            'service_id.exists'   => 'El servicio seleccionado no existe.',
            'attachments.*.file'  => 'Uno de los adjuntos no es un archivo válido.',
            'attachments.*.mimes' => 'Los adjuntos deben ser JPG, PNG, GIF, PDF, ZIP o TXT.',
            'attachments.*.max'   => 'Cada archivo adjunto debe pesar máximo 20 MB.',
        ]
    );

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Revisa los datos del formulario.',
            'errors'  => $validator->errors(),
        ], 422);
    }

    try {
        $ticket = DB::transaction(function () use ($request, $user) {

            $ticket = Ticket::create([
                'user_id'       => $user->id,
                'service_id'    => $request->input('service_id'),
                'ticket_number' => $this->generateTicketNumber(),
                'subject'       => $request->input('subject'),
                'priority'      => $request->input('priority'),
                'status'        => 'open',
                'description'   => $request->input('message'),
                'department'    => $request->input('department'),
            ]);

            $attachmentsData = $this->storeTicketAttachments($request, $ticket);

            TicketReply::create([
                'ticket_id'      => $ticket->id,
                'user_id'        => $user->id,
                'message'        => $request->input('message'),
                'is_internal'    => false,
                'attachments'    => !empty($attachmentsData) ? $attachmentsData : null,
            ]);

            return $ticket;
        });

        $ticket->load(['assignedTo', 'service', 'replies.user'])
               ->loadCount('replies');

        // Avisar al staff (admin/support) de la nueva solicitud de soporte.
        \App\Domains\Platform\Support\AdminNotifier::notifyStaff(
            'Nuevo ticket de soporte',
            "{$user->full_name} abrió el ticket #{$ticket->ticket_number} ({$ticket->department}, prioridad {$ticket->priority}): {$ticket->subject}",
            'admin_ticket_created',
            ['ticket_id' => $ticket->uuid ?? $ticket->id, 'ticket_number' => $ticket->ticket_number, 'priority' => $ticket->priority],
            ['email' => true, 'action_url' => '/admin/tickets', 'action_text' => 'Atender ticket', 'subtitle' => 'Nueva solicitud de soporte'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Ticket creado correctamente.',
            'data'    => $ticket,
        ], 201);
    } catch (\Throwable $e) {
        report($e);

        return response()->json([
            'success' => false,
            'message' => 'Ocurrió un error inesperado al crear el ticket.',
        ], 500);
    }
}

    /**
     * Add a reply to a ticket
     */
    public function addReply(Request $request, string $uuid): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'message' => 'nullable|string|max:5000|required_without:attachments',
        'attachments' => 'nullable|array',
        'attachments.*' => 'file|mimes:jpg,jpeg,png,gif,pdf,zip,txt|max:20480', // 20MB
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Error de validación.',
            'errors' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();
    try {
        $user = Auth::user();
        $ticket = Ticket::where('uuid', $uuid)
                      ->where('user_id', $user->id)
                      ->first();

        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'Ticket no encontrado.'], 404);
        }

        if ($ticket->status === 'closed') {
            return response()->json(['success' => false, 'message' => 'No se puede responder a un ticket cerrado.'], 400);
        }

        $attachmentsData = $this->storeTicketAttachments($request, $ticket);

        // 2. Crear la respuesta en la base de datos
        $reply = TicketReply::create([
            'ticket_id'      => $ticket->id,
            'user_id'        => $user->id,
            'message'        => $request->input('message', ''),
            'is_internal'    => false,
            'attachments'    => !empty($attachmentsData) ? $attachmentsData : null,
        ]);

        // Actualizar el estado del ticket
        if ($ticket->status === 'resolved' || $ticket->status === 'waiting_customer') {
            $ticket->update(['status' => 'in_progress']);
        }

        DB::commit();

        // 3. Cargar la relación del usuario para la respuesta JSON
        $reply->load('user');

        // 4. Broadcast en tiempo real (cliente y admins suscritos al canal)
        event(new \App\Domains\Platform\Events\TicketReplied($ticket, $reply));

        return response()->json([
            'success' => true,
            'message' => 'Respuesta añadida correctamente.',
            // El modelo se encargará de añadir las URLs completas a los adjuntos
            'data'    => $reply,
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();

        // Aquí podrías añadir lógica para eliminar los archivos subidos si la transacción falla
        // if (!empty($attachmentsData)) {
        //     foreach ($attachmentsData as $attachment) {
        //         Storage::disk('public')->delete($attachment['path']);
        //     }
        // }

        return response()->json([
            'success' => false,
            'message' => 'Ocurrió un error al añadir la respuesta.',
            'debug'   => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

    /**
     * Close a ticket
     */
    public function close(string $uuid): JsonResponse
    {
        try {
            $user = Auth::user();
            $ticket = Ticket::where('uuid', $uuid)
                          ->where('user_id', $user->id)
                          ->first();

            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not found'
                ], 404);
            }

            $ticket->update([
                'status' => 'closed',
                'closed_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ticket closed successfully',
                'data' => $ticket
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error closing ticket',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
    }
}

    private function storeTicketAttachments(Request $request, Ticket $ticket): array
    {
        if (!$request->hasFile('attachments')) {
            return [];
        }

        $files = $request->file('attachments');
        $files = is_array($files) ? $files : [$files];
        $attachmentsData = [];

        foreach ($files as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $filePath = $file->store('ticket_attachments/' . $ticket->uuid, 'public');

            if ($filePath === false) {
                throw new \Exception('No se pudo guardar el archivo: ' . $originalName);
            }

            $attachmentsData[] = [
                'path'      => $filePath,
                'name'      => $originalName,
                'size'      => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ];
        }

        return $attachmentsData;
    }

    /**
     * Get ticket statistics
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $activeStatuses = ['open', 'in_progress', 'waiting_customer'];

            $baseQuery = Ticket::where('user_id', $user->id);
            $totalTickets = (clone $baseQuery)->count();
            $openTickets = (clone $baseQuery)->where('status', 'open')->count();
            $activeTickets = (clone $baseQuery)->whereIn('status', $activeStatuses)->count();
            $inProgressTickets = (clone $baseQuery)->where('status', 'in_progress')->count();
            $waitingCustomerTickets = (clone $baseQuery)->where('status', 'waiting_customer')->count();
            $pendingTickets = (clone $baseQuery)->whereIn('status', ['pending', 'waiting_customer'])->count();
            $resolvedTickets = (clone $baseQuery)->where('status', 'resolved')->count();
            $closedTickets = (clone $baseQuery)->where('status', 'closed')->count();
            $resolvedOrClosedTickets = (clone $baseQuery)->whereIn('status', ['resolved', 'closed'])->count();
            $highPriorityTickets = (clone $baseQuery)
                ->whereIn('priority', ['high', 'urgent'])
                ->whereNotIn('status', ['resolved', 'closed'])
                ->count();

            $firstStaffReplies = DB::table('ticket_replies')
                ->join('tickets', 'tickets.id', '=', 'ticket_replies.ticket_id')
                ->where('tickets.user_id', $user->id)
                ->whereNull('tickets.deleted_at')
                ->whereNull('ticket_replies.deleted_at')
                ->where('ticket_replies.is_internal', false)
                ->whereColumn('ticket_replies.user_id', '!=', 'tickets.user_id')
                ->groupBy('ticket_replies.ticket_id', 'tickets.created_at')
                ->select([
                    'tickets.created_at as ticket_created_at',
                    DB::raw('MIN(ticket_replies.created_at) as first_staff_reply_at'),
                ])
                ->get();

            $responseTimes = $firstStaffReplies
                ->map(function ($row) {
                    $createdAt = Carbon::parse($row->ticket_created_at);
                    $firstReplyAt = Carbon::parse($row->first_staff_reply_at);

                    return max(0, $createdAt->diffInMinutes($firstReplyAt, false));
                })
                ->filter(fn ($minutes) => $minutes >= 0)
                ->values();

            $avgFirstResponseMinutes = $responseTimes->isNotEmpty()
                ? (int) round($responseTimes->avg())
                : null;

            $latestActivityAt = (clone $baseQuery)
                ->whereNotNull('last_reply_at')
                ->max('last_reply_at') ?: (clone $baseQuery)->max('updated_at');

            $availableAgents = Agent::available()->count();
            $onlineAgents = Agent::active()
                ->whereNotNull('last_activity_at')
                ->where('last_activity_at', '>=', now()->subMinutes(15))
                ->count();

            $stats = [
                'total_tickets' => $totalTickets,
                'open_tickets' => $openTickets,
                'active_tickets' => $activeTickets,
                'in_progress_tickets' => $inProgressTickets,
                'waiting_customer_tickets' => $waitingCustomerTickets,
                'resolved_tickets' => $resolvedTickets,
                'closed_tickets' => $closedTickets,
                'high_priority_tickets' => $highPriorityTickets,
                'avg_first_response_minutes' => $avgFirstResponseMinutes,
                'avg_first_response_label' => $this->formatResponseMinutes($avgFirstResponseMinutes),
                'tickets_with_staff_response' => $responseTimes->count(),
                'latest_activity_at' => $latestActivityAt
                    ? Carbon::parse($latestActivityAt)->toISOString()
                    : null,
                'support' => [
                    'online' => $onlineAgents > 0,
                    'available_agents' => $availableAgents,
                    'recently_active_agents' => $onlineAgents,
                ],
                // Aliases kept for existing ROKE screens that still consume the old stats shape.
                'total' => $totalTickets,
                'open' => $openTickets,
                'pending' => $pendingTickets,
                'resolved' => $resolvedOrClosedTickets,
                'closed' => $closedTickets,
                'avg_response_minutes' => $avgFirstResponseMinutes,
                'avgResponseMinutes' => $avgFirstResponseMinutes,
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving ticket statistics',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function formatResponseMinutes(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return $remainingMinutes > 0
                ? "{$hours} h {$remainingMinutes} min"
                : "{$hours} h";
        }

        $days = intdiv($hours, 24);
        $remainingHours = $hours % 24;

        return $remainingHours > 0
            ? "{$days} d {$remainingHours} h"
            : "{$days} d";
    }

    /**
     * Generate unique ticket number
     */
    private function generateTicketNumber(): string
    {
        $prefix = config('app.ticket_prefix', 'TKT-');
        $year = date('Y');

        // Get the last ticket number for this year
        $lastTicket = Ticket::where('ticket_number', 'like', $prefix . $year . '%')
                          ->orderBy('ticket_number', 'desc')
                          ->first();

        if ($lastTicket) {
            $lastNumber = (int) substr($lastTicket->ticket_number, -6);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . $year . str_pad($newNumber, 6, '0', STR_PAD_LEFT);
    }
}
