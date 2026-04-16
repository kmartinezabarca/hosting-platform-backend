<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\TicketResource;
use App\Http\Resources\UserResource;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\InvoiceReady;
use App\Services\DashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(private readonly DashboardStatsService $dashboardStats)
    {
    }

    // ──────────────────────────────────────────────
    // Dashboard
    // ──────────────────────────────────────────────

    public function getDashboardStats(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->dashboardStats->getAll(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas del dashboard.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ──────────────────────────────────────────────
    // Users
    // ──────────────────────────────────────────────

    public function getUsers(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 15), 100);
        $search  = $request->get('search');

        $allowedSort = ['created_at', 'first_name', 'last_name', 'email', 'status', 'role', 'last_login_at'];
        $sortBy      = in_array($request->get('sort_by'), $allowedSort) ? $request->get('sort_by') : 'created_at';
        $sortOrder   = $request->get('sort_order') === 'asc' ? 'asc' : 'desc';

        $users = User::query()
            ->when($search, fn($q) => $q->where(fn($q) =>
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%")
                  ->orWhere('email',      'like', "%{$search}%")
                  ->orWhere('phone',      'like', "%{$search}%")
            ))
            ->when($request->get('status'), fn($q, $v) => $q->where('status', $v))
            ->when($request->get('role'),   fn($q, $v) => $q->where('role', $v))
            ->withCount(['services', 'invoices', 'tickets'])
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function createUser(StoreUserRequest $request): JsonResponse
    {
        $data             = $request->validated();
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado exitosamente.',
            'data'    => new UserResource($user),
        ], 201);
    }

    public function updateUser(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $this->authorize('update', $user);

        $data = $request->validated();
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado.',
            'data'    => new UserResource($user->fresh()),
        ]);
    }

    public function deleteUser(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $this->authorize('delete', $user);

        $user->delete();

        return response()->json(['success' => true, 'message' => 'Usuario eliminado.']);
    }

    public function updateUserStatus(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $this->authorize('updateStatus', $user);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'pending_verification', 'banned'])],
        ]);

        $user->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Estado del usuario actualizado.',
            'data'    => new UserResource($user->fresh()),
        ]);
    }

    // ──────────────────────────────────────────────
    // Services
    // ──────────────────────────────────────────────

    public function getServices(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 15), 100);
        $search  = $request->get('search');

        $allowedSort = ['created_at', 'name', 'domain', 'status', 'next_due_date'];
        $sortBy      = in_array($request->get('sort_by'), $allowedSort) ? $request->get('sort_by') : 'created_at';
        $sortOrder   = $request->get('sort_order') === 'asc' ? 'asc' : 'desc';

        $services = Service::with(['user', 'plan'])
            ->when($search, fn($q) => $q->where(fn($q) =>
                $q->where('name',   'like', "%{$search}%")
                  ->orWhere('domain', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) =>
                      $u->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name',  'like', "%{$search}%")
                        ->orWhere('email',      'like', "%{$search}%")
                  )
            ))
            ->when($request->get('status'),  fn($q, $v) => $q->where('status', $v))
            ->when($request->get('plan_id'), fn($q, $v) => $q->where('plan_id', $v))
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

        return response()->json(['success' => true, 'data' => $services]);
    }

    public function getService(int $id): JsonResponse
    {
        $service = Service::with(['user', 'plan.category', 'plan.features', 'plan.pricing.billingCycle'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $service]);
    }

    public function updateServiceStatus(Request $request, int $serviceId): JsonResponse
    {
        $service = Service::findOrFail($serviceId);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'maintenance', 'cancelled'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $service->update([
            'status'      => $validated['status'],
            'admin_notes' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estado del servicio actualizado.',
            'data'    => $service->fresh(),
        ]);
    }

    // ──────────────────────────────────────────────
    // Invoices
    // ──────────────────────────────────────────────

    public function getInvoices(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 15), 100);
        $search  = $request->get('search');

        $allowedSort = ['created_at', 'invoice_number', 'total', 'due_date', 'paid_at', 'status'];
        $sortBy      = in_array($request->get('sort_by'), $allowedSort) ? $request->get('sort_by') : 'created_at';
        $sortOrder   = $request->get('sort_order') === 'asc' ? 'asc' : 'desc';

        $invoices = Invoice::with(['user', 'items'])
            ->when($search, fn($q) => $q->where(fn($q) =>
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) =>
                      $u->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name',  'like', "%{$search}%")
                        ->orWhere('email',      'like', "%{$search}%")
                  )
            ))
            ->when($request->get('status'), fn($q, $v) => $q->where('status', $v))
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

        return response()->json(['success' => true, 'data' => $invoices]);
    }

    public function updateInvoiceStatus(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = Invoice::findOrFail($invoiceId);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'paid', 'overdue', 'cancelled'])],
            'notes'  => ['nullable', 'string', 'max:500'],
        ]);

        $invoice->update([
            'status'  => $validated['status'],
            'notes'   => $validated['notes'] ?? $invoice->notes,
            'paid_at' => $validated['status'] === 'paid' ? now() : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estado de factura actualizado.',
            'data'    => $invoice->fresh(),
        ]);
    }

    public function markInvoiceAsPaid(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $invoice->update([
            'status'         => 'paid',
            'paid_at'        => now(),
            'payment_method' => $request->input('payment_method', 'manual'),
            'notes'          => $request->input('notes', 'Marcado como pagado por administrador.'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Factura marcada como pagada.',
            'data'    => $invoice->fresh(),
        ]);
    }

    public function sendInvoiceReminder(int $id): JsonResponse
    {
        $invoice = Invoice::with('user')->findOrFail($id);

        if (!$invoice->user) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado.'], 404);
        }

        $invoice->user->notify(new InvoiceReady($invoice));

        return response()->json(['success' => true, 'message' => 'Recordatorio de factura enviado.']);
    }

    public function cancelInvoice(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $invoice->update([
            'status' => 'cancelled',
            'notes'  => $request->input('reason', 'Cancelada por administrador.'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Factura cancelada.',
            'data'    => $invoice->fresh(),
        ]);
    }

    // ──────────────────────────────────────────────
    // Tickets
    // ──────────────────────────────────────────────

    public function getTickets(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 15), 100);
        $search  = $request->get('search');

        $allowedSort = ['created_at', 'subject', 'status', 'priority', 'updated_at'];
        $sortBy      = in_array($request->get('sort_by'), $allowedSort) ? $request->get('sort_by') : 'created_at';
        $sortOrder   = $request->get('sort_order') === 'asc' ? 'asc' : 'desc';

        $tickets = Ticket::with(['user', 'assignedTo', 'service'])
            ->when($search, fn($q) => $q->where(fn($q) =>
                $q->where('subject',       'like', "%{$search}%")
                  ->orWhere('ticket_number', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) =>
                      $u->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name',  'like', "%{$search}%")
                        ->orWhere('email',      'like', "%{$search}%")
                  )
            ))
            ->when($request->get('status'),     fn($q, $v) => $q->where('status', $v))
            ->when($request->get('priority'),   fn($q, $v) => $q->where('priority', $v))
            ->when($request->get('department'), fn($q, $v) => $q->where('department', $v))
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

        return response()->json(['success' => true, 'data' => $tickets]);
    }

    public function assignTicket(Request $request, int $ticketId): JsonResponse
    {
        $ticket = Ticket::findOrFail($ticketId);

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
            'status'      => ['sometimes', Rule::in(['open', 'in_progress', 'resolved', 'closed'])],
        ]);

        $ticket->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Ticket asignado.',
            'data'    => new TicketResource($ticket->load('assignedTo')),
        ]);
    }

    public function updateTicketStatus(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'resolved', 'closed', 'pending'])],
        ]);

        $updates = ['status' => $validated['status']];
        if ($validated['status'] === 'closed') {
            $updates['closed_at'] = now();
        }

        $ticket->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'Estado del ticket actualizado.',
            'data'    => new TicketResource($ticket->fresh()),
        ]);
    }

    public function updateTicketPriority(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::findOrFail($id);

        $validated = $request->validate([
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
        ]);

        $ticket->update(['priority' => $validated['priority']]);

        return response()->json([
            'success' => true,
            'message' => 'Prioridad del ticket actualizada.',
            'data'    => new TicketResource($ticket->fresh()),
        ]);
    }

    public function addTicketReply(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::findOrFail($id);

        $validated = $request->validate([
            'message'     => ['required', 'string'],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        $reply = TicketReply::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => auth()->id(),
            'message'     => $validated['message'],
            'is_internal' => (bool) ($validated['is_internal'] ?? false),
        ]);

        // Update ticket status to in_progress when staff replies
        if ($ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Respuesta agregada.',
            'data'    => $reply->load('user'),
        ], 201);
    }

    public function createTicket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'     => ['required', 'exists:users,id'],
            'subject'     => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority'    => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status'      => ['sometimes', Rule::in(['open', 'in_progress', 'resolved', 'closed', 'pending'])],
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);

        $ticket = Ticket::create(array_merge($validated, [
            'priority'      => $validated['priority']  ?? 'medium',
            'status'        => $validated['status']    ?? 'open',
            'ticket_number' => 'TKT-' . strtoupper(Str::random(8)),
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Ticket creado.',
            'data'    => new TicketResource($ticket->load(['user', 'assignedTo'])),
        ], 201);
    }

    public function deleteTicket(int $id): JsonResponse
    {
        Ticket::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Ticket eliminado.']);
    }

    // ──────────────────────────────────────────────
    // Support helpers
    // ──────────────────────────────────────────────

    public function getSupportAgents(): JsonResponse
    {
        $agents = User::whereIn('role', ['admin', 'support'])
            ->select('id', 'uuid', 'first_name', 'last_name', 'email')
            ->orderBy('first_name')
            ->get();

        return response()->json(['success' => true, 'data' => $agents]);
    }

    /**
     * Ticket departments — driven by config so they can be extended without code changes.
     */
    public function getTicketCategories(): JsonResponse
    {
        $categories = config('support.departments', [
            ['id' => 'technical',  'name' => 'Soporte Técnico'],
            ['id' => 'billing',    'name' => 'Facturación'],
            ['id' => 'sales',      'name' => 'Ventas'],
            ['id' => 'general',    'name' => 'General'],
        ]);

        return response()->json(['success' => true, 'data' => $categories]);
    }
}
