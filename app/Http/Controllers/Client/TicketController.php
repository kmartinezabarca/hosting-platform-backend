<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    /**
     * Get user's tickets
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $query = Ticket::where('user_id', $user->id);

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by priority if provided
            if ($request->has('priority')) {
                $query->where('priority', $request->priority);
            }

            // Filter by department if provided
            if ($request->has('department')) {
                $query->where('department', $request->department);
            }

            $tickets = $query
                           ->with(['assignedTo', 'service', 'lastReply.user'])
                           ->withCount('replies')
                           ->orderBy('created_at', 'desc')
                           ->paginate($request->get('per_page', 15));

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
                'error' => $e->getMessage()
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new ticket
     */
    public function store(Request $request): JsonResponse
{
    // Validaciones con mensajes personalizados
    $validator = Validator::make(
        $request->all(),
        [
            'subject'     => 'required|string|max:500',
            'message'     => 'required|string',
            'priority'    => 'required|in:low,medium,high,urgent',
            'department'  => 'required|in:technical,billing,sales,abuse',
            'service_id'  => 'nullable|exists:services,id',
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
        ]
    );

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Revisa los datos del formulario.',
            'errors'  => $validator->errors(),
        ], 422);
    }

    $user = Auth::user();

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

            TicketReply::create([
                'ticket_id'      => $ticket->id,
                'user_id'        => $user->id,
                'message'        => $request->input('message'),
            ]);

            return $ticket;
        });

        $ticket->load(['assignedTo', 'service', 'replies.user'])
               ->loadCount('replies');

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

        $attachmentsData = [];

        if ($request->hasFile('attachments')) {

            foreach ($request->file('attachments') as $index => $file) {

                if (!$file->isValid()) {
                    continue;
                }

                $originalName = $file->getClientOriginalName();
                $pathPrefix = 'ticket_attachments/' . $ticket->uuid;
                $filePath = $file->store($pathPrefix, 'public');

                // Si $filePath es false, el guardado falló
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
        } else {
            \Illuminate\Support\Facades\Log::info('No se encontraron archivos en la petición.');
        }

        // 2. Crear la respuesta en la base de datos
        $reply = TicketReply::create([
            'ticket_id'      => $ticket->id,
            'user_id'        => $user->id,
            'message'        => $request->input('message', ''),
            'is_staff_reply' => false,
            'attachments'    => !empty($attachmentsData) ? $attachmentsData : null,
        ]);

        // Actualizar el estado del ticket
        if ($ticket->status === 'resolved' || $ticket->status === 'waiting_customer') {
            $ticket->update(['status' => 'in_progress']);
        }

        DB::commit();

        // 3. Cargar la relación del usuario para la respuesta JSON
        $reply->load('user');

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
            'error'   => $e->getMessage()
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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get ticket statistics
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $stats = [
                'total_tickets' => Ticket::where('user_id', $user->id)->count(),
                'open_tickets' => Ticket::where('user_id', $user->id)->where('status', 'open')->count(),
                'in_progress_tickets' => Ticket::where('user_id', $user->id)->where('status', 'in_progress')->count(),
                'waiting_customer_tickets' => Ticket::where('user_id', $user->id)->where('status', 'waiting_customer')->count(),
                'resolved_tickets' => Ticket::where('user_id', $user->id)->where('status', 'resolved')->count(),
                'closed_tickets' => Ticket::where('user_id', $user->id)->where('status', 'closed')->count(),
                'high_priority_tickets' => Ticket::where('user_id', $user->id)->whereIn('priority', ['high', 'urgent'])->whereNotIn('status', ['resolved', 'closed'])->count()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving ticket statistics',
                'error' => $e->getMessage()
            ], 500);
        }
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

