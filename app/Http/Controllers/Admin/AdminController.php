<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreInvoiceRequest;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\ServicePlan;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\TicketResource;
use App\Http\Resources\UserResource;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\InvoiceReady;
use App\Services\DashboardStatsService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
        private readonly InvoiceService $invoiceService,
    ) {
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

    public function createService(StoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        // ── 1. Cargar plan con su categoría para determinar el tipo ──────────
        $plan = ServicePlan::with('category')->findOrFail($data['service_plan_id']);
        unset($data['service_plan_id']);

        $categorySlug = $plan->category?->slug ?? '';
        $isInfra      = in_array($categorySlug, Service::INFRA_SLUGS, true);
        $isPro        = in_array($categorySlug, Service::PROFESSIONAL_SLUGS, true);

        // ── 2. Validación específica por tipo ────────────────────────────────
        if ($isInfra && ! empty($data['domain'])) {
            // El dominio debe ser único entre servicios de infraestructura activos
            $exists = Service::whereHas('plan.category', fn($q) => $q->whereIn('slug', Service::INFRA_SLUGS))
                ->where('domain', $data['domain'])
                ->whereNull('terminated_at')
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validación fallida.',
                    'errors'  => ['domain' => ['Este dominio ya está registrado en un servicio activo.']],
                ], 422);
            }
        }

        if ($isPro && $data['billing_cycle'] !== 'one_time' && $isInfra === false) {
            // Para servicios profesionales one_time, next_due_date = fecha de entrega acordada
            // Si no se envía y es one_time, calculamos 30 días por defecto
        }

        // ── 3. Precios desde el plan si no se sobreescriben ──────────────────
        $data['price']     = $data['price']     ?? (float) $plan->base_price;
        $data['setup_fee'] = $data['setup_fee'] ?? (float) $plan->setup_fee;

        // ── 4. Nombre automático por tipo ────────────────────────────────────
        if (empty($data['name'])) {
            $user = User::find($data['user_id']);
            $data['name'] = $isInfra && ! empty($data['domain'])
                ? "{$plan->name} — {$data['domain']}"
                : "{$plan->name} — " . ($user?->full_name ?? $user?->email ?? 'cliente');
        }

        // ── 5. next_due_date por billing_cycle ───────────────────────────────
        if (empty($data['next_due_date'])) {
            $data['next_due_date'] = match ($data['billing_cycle']) {
                'monthly'       => now()->addMonth()->toDateString(),
                'quarterly'     => now()->addMonths(3)->toDateString(),
                'semi_annually' => now()->addMonths(6)->toDateString(),
                'annually'      => now()->addYear()->toDateString(),
                'one_time'      => now()->addDays(30)->toDateString(), // Entrega estimada en 30 días
                default         => now()->addMonth()->toDateString(),
            };
        }

        // ── 6. Defaults de configuración según tipo ──────────────────────────
        $configDefaults = $this->configDefaults($categorySlug);
        $data['configuration'] = array_merge(
            $configDefaults,
            $data['configuration'] ?? []
        );

        // ── 7. Crear servicio ────────────────────────────────────────────────
        $service = Service::create(array_merge($data, ['plan_id' => $plan->id]));

        return response()->json([
            'success' => true,
            'message' => 'Servicio creado exitosamente.',
            'data'    => new ServiceResource($service->load('plan.category', 'user')),
        ], 201);
    }

    public function updateService(Request $request, int $id): JsonResponse
    {
        $service = Service::with('plan.category')->findOrFail($id);
        $categorySlug = $service->plan?->category?->slug ?? '';
        $isInfra      = in_array($categorySlug, Service::INFRA_SLUGS, true);

        $validated = $request->validate([
            'name'            => ['sometimes', 'string', 'max:255'],
            'domain'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'          => ['sometimes', Rule::in(['pending', 'active', 'suspended', 'terminated', 'failed'])],
            'billing_cycle'   => ['sometimes', Rule::in(['monthly', 'quarterly', 'semi_annually', 'annually', 'one_time'])],
            'price'           => ['sometimes', 'numeric', 'min:0'],
            'setup_fee'       => ['sometimes', 'numeric', 'min:0'],
            'next_due_date'   => ['sometimes', 'date'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'external_id'     => ['nullable', 'string', 'max:255'],
            'configuration'   => ['nullable', 'array'],
            'server_node_id'  => ['nullable', 'integer', 'exists:server_nodes,id'],
            'service_plan_id' => ['sometimes', 'integer', 'exists:service_plans,id'],
        ]);

        // Dominio único para infraestructura
        if ($isInfra && isset($validated['domain']) && $validated['domain'] !== null) {
            $exists = Service::whereHas('plan.category', fn($q) => $q->whereIn('slug', Service::INFRA_SLUGS))
                ->where('domain', $validated['domain'])
                ->where('id', '!=', $service->id)
                ->whereNull('terminated_at')
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validación fallida.',
                    'errors'  => ['domain' => ['Este dominio ya está registrado en otro servicio activo.']],
                ], 422);
            }
        }

        // Cambio de plan
        if (isset($validated['service_plan_id'])) {
            $plan = ServicePlan::findOrFail($validated['service_plan_id']);
            $validated['plan_id'] = $plan->id;
            unset($validated['service_plan_id']);
        }

        // Marcar terminated_at cuando se termina
        if (isset($validated['status']) && $validated['status'] === 'terminated' && ! $service->terminated_at) {
            $validated['terminated_at'] = now();
        }

        // Merge de configuration — no reemplazar todo, solo sobreescribir claves enviadas
        if (isset($validated['configuration'])) {
            $validated['configuration'] = array_merge(
                $service->configuration ?? [],
                $validated['configuration']
            );
        }

        $service->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Servicio actualizado.',
            'data'    => new ServiceResource($service->fresh()->load('plan.category', 'user')),
        ]);
    }

    public function deleteService(int $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        if ($service->status === 'active') {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un servicio activo. Suspéndelo o termínalo primero.',
            ], 422);
        }

        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Servicio eliminado correctamente.',
        ]);
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

    public function createInvoice(StoreInvoiceRequest $request): JsonResponse
    {
        $data  = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        // ── Cálculo fiscal México ─────────────────────────────────────────
        // Subtotal = suma de (cantidad × precio unitario) por concepto
        $subtotal = collect($items)->sum(fn($i) => round((float) $i['quantity'] * (float) $i['unit_price'], 2));

        // IVA: 16 % estándar en México si no se especifica
        $taxRate   = isset($data['tax_rate']) ? (float) $data['tax_rate'] : 16.0;
        $taxAmount = round($subtotal * $taxRate / 100, 2);
        $total     = round($subtotal + $taxAmount, 2);

        $invoiceData = array_merge($data, [
            'subtotal'   => $subtotal,
            'tax_rate'   => $taxRate,
            'tax_amount' => $taxAmount,
            'total'      => $total,
            'currency'   => $data['currency'] ?? config('billing.currency', 'MXN'),
            'paid_at'    => ($data['status'] === Invoice::STATUS_PAID) ? now() : null,
        ]);

        $invoice = $this->invoiceService->createWithItems($invoiceData, $items);

        return response()->json([
            'success' => true,
            'message' => 'Factura creada exitosamente.',
            'data'    => new InvoiceResource($invoice->load('items', 'user')),
        ], 201);
    }

    public function updateInvoice(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::with('items')->findOrFail($id);

        $validated = $request->validate([
            'status'            => ['sometimes', Rule::in([
                                        Invoice::STATUS_DRAFT,
                                        Invoice::STATUS_SENT,
                                        Invoice::STATUS_PROCESS,
                                        Invoice::STATUS_PAID,
                                        Invoice::STATUS_OVERDUE,
                                        Invoice::STATUS_CANCELLED,
                                    ])],
            'due_date'          => ['sometimes', 'date'],
            'notes'             => ['nullable', 'string', 'max:1000'],
            'payment_method'    => ['nullable', 'string', 'max:100'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            // Reconceptos: si se envían se reemplaza el listado completo
            'items'             => ['sometimes', 'array', 'min:1'],
            'items.*.description' => ['required_with:items', 'string', 'max:500'],
            'items.*.quantity'    => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price'  => ['required_with:items', 'numeric', 'min:0'],
            'items.*.service_id'  => ['nullable', 'integer', 'exists:services,id'],
        ]);

        DB::transaction(function () use ($invoice, $validated) {
            // Recalcular montos si vienen partidas nuevas
            if (isset($validated['items'])) {
                $subtotal  = collect($validated['items'])->sum(
                    fn($i) => round((float) $i['quantity'] * (float) $i['unit_price'], 2)
                );
                $taxRate   = (float) $invoice->tax_rate;
                $taxAmount = round($subtotal * $taxRate / 100, 2);
                $total     = round($subtotal + $taxAmount, 2);

                $invoice->items()->delete();

                foreach ($validated['items'] as $item) {
                    InvoiceItem::create([
                        'invoice_id'  => $invoice->id,
                        'service_id'  => $item['service_id'] ?? null,
                        'description' => $item['description'],
                        'quantity'    => (int) $item['quantity'],
                        'unit_price'  => (float) $item['unit_price'],
                        'total'       => round((float) $item['quantity'] * (float) $item['unit_price'], 2),
                    ]);
                }

                $validated = array_merge($validated, [
                    'subtotal'   => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total'      => $total,
                ]);
                unset($validated['items']);
            }

            // Marcar paid_at cuando cambia estado a pagado
            if (isset($validated['status']) && $validated['status'] === Invoice::STATUS_PAID && ! $invoice->paid_at) {
                $validated['paid_at'] = now();
            }

            $invoice->update($validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'Factura actualizada.',
            'data'    => new InvoiceResource($invoice->fresh()->load('items', 'user')),
        ]);
    }

    public function deleteInvoice(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        if ($invoice->status === Invoice::STATUS_PAID) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar una factura que ya ha sido pagada. Usa cancelar en su lugar.',
            ], 422);
        }

        $invoice->items()->delete();
        $invoice->delete();

        return response()->json([
            'success' => true,
            'message' => 'Factura eliminada correctamente.',
        ]);
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

    public function showTicket(int $id): JsonResponse
    {
        $ticket = Ticket::with([
            'user',
            'assignedTo',
            'service',
            'replies' => fn($q) => $q->with('user')->orderBy('created_at', 'asc'),
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => new TicketResource($ticket),
        ]);
    }

    public function updateTicket(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::findOrFail($id);

        // Normalise empty strings sent by the frontend
        $request->merge([
            'assigned_to' => $request->input('assigned_to') ?: null,
            'service_id'  => $request->input('service_id')  ?: null,
            'category'    => $request->input('category')    ?: null,
            'department'  => $request->input('department')  ?: null,
        ]);

        $validated = $request->validate([
            'subject'     => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'status'      => ['sometimes', Rule::in(['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'])],
            'priority'    => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'category'    => ['nullable', Rule::in(['technical', 'billing', 'general', 'feature_request', 'bug_report'])],
            'department'  => ['nullable', Rule::in(['technical', 'billing', 'sales', 'abuse', 'general'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'service_id'  => ['nullable', 'integer', 'exists:services,id'],
        ]);

        // Auto-set closed_at when status changes to closed
        if (isset($validated['status']) && $validated['status'] === 'closed' && $ticket->status !== 'closed') {
            $validated['closed_at'] = now();
        } elseif (isset($validated['status']) && $validated['status'] !== 'closed') {
            $validated['closed_at'] = null;
        }

        $ticket->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Ticket actualizado.',
            'data'    => new TicketResource($ticket->fresh()->load(['user', 'assignedTo', 'service'])),
        ]);
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
        // Normalizar strings vacíos → null ANTES de validar
        // El frontend envía "" para campos opcionales no completados
        $input = $request->merge([
            'assigned_to' => $request->input('assigned_to') ?: null,
            'service_id'  => $request->input('service_id')  ?: null,
            'category'    => $request->input('category')    ?: null,
            'department'  => $request->input('department')  ?: null,
        ])->all();

        $validated = validator($input, [
            'user_id'     => ['required', 'integer', 'exists:users,id'],
            'service_id'  => ['nullable', 'integer', 'exists:services,id'],
            'subject'     => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority'    => ['sometimes', 'nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status'      => ['sometimes', 'nullable', Rule::in(['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'])],
            'category'    => ['nullable', Rule::in(['technical', 'billing', 'general', 'feature_request', 'bug_report'])],
            'department'  => ['nullable', Rule::in(['technical', 'billing', 'sales', 'abuse', 'general'])],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'user_id.required'    => 'El cliente es obligatorio.',
            'user_id.exists'      => 'El cliente seleccionado no existe.',
            'subject.required'    => 'El asunto del ticket es obligatorio.',
            'description.required'=> 'La descripción del ticket es obligatoria.',
            'priority.in'         => 'Prioridad inválida. Use: low, medium, high o urgent.',
            'status.in'           => 'Estado inválido. Use: open, in_progress, waiting_customer, resolved o closed.',
            'category.in'         => 'Categoría inválida.',
            'assigned_to.exists'  => 'El agente asignado no existe.',
        ])->validate();

        // Número secuencial: TKT-YYYYMM-NNNN
        $prefix = 'TKT-' . now()->format('Ym') . '-';
        $last   = \App\Models\Ticket::where('ticket_number', 'like', $prefix . '%')
                    ->orderByDesc('ticket_number')->value('ticket_number');
        $seq    = $last ? ((int) substr($last, -4)) + 1 : 1;

        $ticket = \App\Models\Ticket::create(array_merge($validated, [
            'priority'      => $validated['priority']  ?? 'medium',
            'status'        => $validated['status']    ?? 'open',
            'department'    => $validated['department'] ?? 'technical',
            'ticket_number' => $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT),
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Ticket creado.',
            'data'    => new TicketResource($ticket->load(['user', 'assignedTo', 'service'])),
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

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    /**
     * Devuelve la estructura de configuration por defecto según el slug de categoría.
     * El frontend usa estas claves para saber qué campos mostrar en el formulario.
     */
    private function configDefaults(string $categorySlug): array
    {
        return match ($categorySlug) {
            // ── Infraestructura ─────────────────────────────────────────────
            'hosting' => [
                'panel_url'     => null,   // https://cpanel.roke.mx/user
                'ip_address'    => null,
                'disk_gb'       => null,
                'bandwidth_gb'  => null,
                'email_accounts'=> null,
                'subdomains'    => null,
                'php_version'   => null,
            ],
            'vps' => [
                'ip_address'    => null,
                'ssh_port'      => 22,
                'os'            => null,   // 'Ubuntu 22.04', 'AlmaLinux 9', etc.
                'cpu_cores'     => null,
                'ram_gb'        => null,
                'disk_gb'       => null,
                'proxmox_vmid'  => null,
                'panel_url'     => null,
            ],
            'database' => [
                'host'          => null,
                'port'          => 3306,
                'engine'        => null,   // 'MySQL 8.0', 'PostgreSQL 16', 'MongoDB 7'
                'database_name' => null,
                'storage_gb'    => null,
                'backups'       => true,
            ],
            'gameserver' => [
                'server_ip'     => null,
                'server_port'   => null,
                'game'          => null,   // 'Minecraft', 'Valheim', 'CS2', etc.
                'pterodactyl_id'=> null,
                'max_players'   => null,
                'ram_mb'        => null,
                'mod_pack'      => null,
            ],

            // ── Servicios Profesionales ─────────────────────────────────────
            'database-architecture' => [
                'start_date'         => null,
                'end_date'           => null,
                'deliverables'       => [],  // ['Diagrama ERD', 'Plan de indexación']
                'hours_included'     => null,
                'contract_reference' => null,
                'project_manager'    => null,
                'tech_stack'         => [],  // ['MySQL', 'PostgreSQL', 'Redis']
            ],
            'software-development' => [
                'start_date'         => null,
                'end_date'           => null,
                'deliverables'       => [],
                'hours_included'     => null,
                'contract_reference' => null,
                'project_manager'    => null,
                'repository_url'     => null,
                'tech_stack'         => [],
                'methodology'        => null, // 'Scrum', 'Kanban', 'Waterfall'
            ],
            'security-devops' => [
                'start_date'         => null,
                'end_date'           => null,
                'deliverables'       => [],  // ['Informe de vulnerabilidades', 'Pipeline CI/CD']
                'hours_included'     => null,
                'contract_reference' => null,
                'project_manager'    => null,
                'scope'              => null, // 'Auditoría', 'CI/CD', 'IaC', 'Full DevOps'
            ],
            'migration-modernization' => [
                'start_date'         => null,
                'end_date'           => null,
                'deliverables'       => [],
                'hours_included'     => null,
                'contract_reference' => null,
                'project_manager'    => null,
                'source_environment' => null, // 'On-premise', 'AWS', 'cPanel legacy'
                'target_environment' => null, // 'AWS', 'GCP', 'Kubernetes'
            ],
            'critical-support' => [
                'sla_response_minutes' => 15,   // Tiempo máx. de respuesta en minutos
                'sla_uptime_percent'   => 99.9,
                'contact_channels'     => ['email', 'phone', 'slack'],
                'escalation_contact'   => null,
                'monitoring_tool'      => null,  // 'Datadog', 'Zabbix', 'custom'
                'covered_systems'      => [],
            ],
            default => [],
        };
    }
}
