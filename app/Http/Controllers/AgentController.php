<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\User;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class AgentController extends Controller
{
    /**
     * Obtener lista de agentes con filtros y paginación
     */
    public function index(Request $request)
    {
        try {
            $query = Agent::with(['user:id,first_name,last_name,email,avatar_url']);

            // Filtros
            if ($request->has('status') && $request->status !== '') {
                $query->where('status', $request->status);
            }

            if ($request->has('department') && $request->department !== '') {
                $query->where('department', $request->department);
            }

            if ($request->has('specialization') && $request->specialization !== '') {
                $query->where('specialization', $request->specialization);
            }

            if ($request->has('available') && $request->available === 'true') {
                $query->available();
            }

            // Búsqueda por nombre o email
            if ($request->has('search') && $request->search !== '') {
                $search = $request->search;
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                })->orWhere('agent_code', 'like', "%{$search}%");
            }

            // Ordenamiento
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            
            $allowedSorts = ['created_at', 'performance_rating', 'current_ticket_count', 'total_tickets_resolved', 'last_activity_at'];
            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortOrder);
            }

            // Paginación
            $perPage = min($request->get('per_page', 15), 100);
            $agents = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $agents->items(),
                'pagination' => [
                    'current_page' => $agents->currentPage(),
                    'last_page' => $agents->lastPage(),
                    'per_page' => $agents->perPage(),
                    'total' => $agents->total(),
                    'from' => $agents->firstItem(),
                    'to' => $agents->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener agentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear nuevo agente
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'department' => 'required|string|max:100',
                'specialization' => 'required|in:general,technical,billing,sales,escalation',
                'max_concurrent_tickets' => 'integer|min:1|max:50',
                'working_hours' => 'nullable|array',
                'skills' => 'nullable|array',
                'notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar que el usuario no sea ya un agente
            $existingAgent = Agent::where('user_id', $request->user_id)->first();
            if ($existingAgent) {
                return response()->json([
                    'success' => false,
                    'message' => 'El usuario ya es un agente'
                ], 422);
            }

            // Verificar que el usuario tenga rol de support o admin
            $user = User::find($request->user_id);
            if (!in_array($user->role, ['admin', 'support'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'El usuario debe tener rol de admin o support'
                ], 422);
            }

            $agent = Agent::create([
                'user_id' => $request->user_id,
                'department' => $request->department,
                'specialization' => $request->specialization,
                'max_concurrent_tickets' => $request->get('max_concurrent_tickets', 10),
                'working_hours' => $request->working_hours,
                'skills' => $request->skills,
                'notes' => $request->notes
            ]);

            $agent->load('user:id,first_name,last_name,email,avatar_url');

            return response()->json([
                'success' => true,
                'message' => 'Agente creado exitosamente',
                'data' => $agent
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear agente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar agente específico
     */
    public function show($uuid)
    {
        try {
            $agent = Agent::with(['user:id,first_name,last_name,email,avatar_url,phone,company'])
                          ->where('uuid', $uuid)
                          ->first();

            if (!$agent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agente no encontrado'
                ], 404);
            }

            // Agregar estadísticas adicionales
            $agent->statistics = [
                'tickets_open' => $agent->tickets()->where('status', 'open')->count(),
                'tickets_in_progress' => $agent->tickets()->where('status', 'in_progress')->count(),
                'tickets_resolved_this_month' => $agent->tickets()
                    ->where('status', 'resolved')
                    ->whereMonth('updated_at', now()->month)
                    ->count(),
                'average_rating' => $agent->performance_rating
            ];

            return response()->json([
                'success' => true,
                'data' => $agent
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener agente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar agente
     */
    public function update(Request $request, $uuid)
    {
        try {
            $agent = Agent::where('uuid', $uuid)->first();

            if (!$agent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agente no encontrado'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'department' => 'sometimes|string|max:100',
                'specialization' => 'sometimes|in:general,technical,billing,sales,escalation',
                'status' => 'sometimes|in:active,inactive,busy,away',
                'max_concurrent_tickets' => 'sometimes|integer|min:1|max:50',
                'working_hours' => 'nullable|array',
                'skills' => 'nullable|array',
                'notes' => 'nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $agent->update($request->only([
                'department',
                'specialization',
                'status',
                'max_concurrent_tickets',
                'working_hours',
                'skills',
                'notes'
            ]));

            $agent->touch('last_activity_at');
            $agent->load('user:id,first_name,last_name,email,avatar_url');

            return response()->json([
                'success' => true,
                'message' => 'Agente actualizado exitosamente',
                'data' => $agent
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar agente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar agente (soft delete)
     */
    public function destroy($uuid)
    {
        try {
            $agent = Agent::where('uuid', $uuid)->first();

            if (!$agent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agente no encontrado'
                ], 404);
            }

            // Verificar si tiene tickets activos
            $activeTickets = $agent->tickets()
                                  ->whereIn('status', ['open', 'in_progress'])
                                  ->count();

            if ($activeTickets > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el agente porque tiene tickets activos asignados'
                ], 422);
            }

            $agent->delete();

            return response()->json([
                'success' => true,
                'message' => 'Agente eliminado exitosamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar agente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de agentes
     */
    public function statistics()
    {
        try {
            $stats = [
                'total_agents' => Agent::count(),
                'active_agents' => Agent::where('status', 'active')->count(),
                'busy_agents' => Agent::where('status', 'busy')->count(),
                'away_agents' => Agent::where('status', 'away')->count(),
                'inactive_agents' => Agent::where('status', 'inactive')->count(),
                'available_agents' => Agent::available()->count(),
                'departments' => Agent::select('department', DB::raw('count(*) as count'))
                                    ->groupBy('department')
                                    ->get(),
                'specializations' => Agent::select('specialization', DB::raw('count(*) as count'))
                                         ->groupBy('specialization')
                                         ->get(),
                'performance_metrics' => [
                    'average_rating' => Agent::avg('performance_rating'),
                    'total_tickets_resolved' => Agent::sum('total_tickets_resolved'),
                    'average_response_time' => Agent::avg('average_response_time'),
                    'average_resolution_time' => Agent::avg('average_resolution_time')
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar ticket a agente
     */
    public function assignTicket(Request $request, $uuid)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ticket_id' => 'required|exists:tickets,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datos de validación incorrectos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $agent = Agent::where('uuid', $uuid)->first();
            if (!$agent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agente no encontrado'
                ], 404);
            }

            if (!$agent->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El agente no está disponible para nuevos tickets'
                ], 422);
            }

            $ticket = Ticket::find($request->ticket_id);
            if ($ticket->assigned_to) {
                return response()->json([
                    'success' => false,
                    'message' => 'El ticket ya está asignado a otro agente'
                ], 422);
            }

            DB::transaction(function () use ($ticket, $agent) {
                $ticket->update([
                    'assigned_to' => $agent->user_id,
                    'status' => 'in_progress'
                ]);
                
                $agent->incrementTicketCount();
            });

            return response()->json([
                'success' => true,
                'message' => 'Ticket asignado exitosamente al agente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener tickets asignados a un agente
     */
    public function tickets($uuid, Request $request)
    {
        try {
            $agent = Agent::where('uuid', $uuid)->first();
            if (!$agent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agente no encontrado'
                ], 404);
            }

            $query = $agent->tickets()->with(['user:id,first_name,last_name,email']);

            // Filtros
            if ($request->has('status') && $request->status !== '') {
                $query->where('status', $request->status);
            }

            if ($request->has('priority') && $request->priority !== '') {
                $query->where('priority', $request->priority);
            }

            // Ordenamiento
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Paginación
            $perPage = min($request->get('per_page', 15), 100);
            $tickets = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $tickets->items(),
                'pagination' => [
                    'current_page' => $tickets->currentPage(),
                    'last_page' => $tickets->lastPage(),
                    'per_page' => $tickets->perPage(),
                    'total' => $tickets->total()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tickets del agente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener agente recomendado para asignación automática
     */
    public function getRecommendedAgent(Request $request)
    {
        try {
            $department = $request->get('department', 'support');
            $specialization = $request->get('specialization');

            $agent = Agent::getLeastBusyAgent($department, $specialization);

            if (!$agent) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay agentes disponibles en este momento'
                ], 404);
            }

            $agent->load('user:id,first_name,last_name,email');

            return response()->json([
                'success' => true,
                'data' => $agent
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener agente recomendado',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

