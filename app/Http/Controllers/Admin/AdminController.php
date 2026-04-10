<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Models\User;
use App\Models\Service;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\Transaction;
use App\Models\ServicePlan;
use App\Models\AddOn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class AdminController extends Controller
{
    /**
     * Get comprehensive admin dashboard statistics
     */
    public function getDashboardStats()
    {
        try {
            // Users statistics
            $usersStats = [
                'total' => User::count(),
                'active' => User::where('status', 'active')->count(),
                'pending' => User::where('status', 'pending_verification')->count(),
                'suspended' => User::where('status', 'suspended')->count(),
                'new_this_month' => User::whereMonth('created_at', now()->month)->count(),
                'growth_rate' => $this->calculateGrowthRate(User::class, 'created_at')
            ];

            // Services statistics
            $servicesStats = [
                'total' => Service::count(),
                'active' => Service::where('status', 'active')->count(),
                'suspended' => Service::where('status', 'suspended')->count(),
                'maintenance' => Service::where('status', 'maintenance')->count(),
                'cancelled' => Service::where('status', 'cancelled')->count(),
                'new_this_month' => Service::whereMonth('created_at', now()->month)->count(),
                'growth_rate' => $this->calculateGrowthRate(Service::class, 'created_at')
            ];

            // Revenue statistics
            $monthlyRevenue = Invoice::where('status', 'paid')
                ->whereMonth('created_at', now()->month)
                ->sum('total');
            
            $yearlyRevenue = Invoice::where('status', 'paid')
                ->whereYear('created_at', now()->year)
                ->sum('total');

            $revenueStats = [
                'monthly' => $monthlyRevenue,
                'yearly' => $yearlyRevenue,
                'currency' => 'MXN',
                'growth_rate' => $this->calculateRevenueGrowthRate()
            ];

            // Invoices statistics
            $invoicesStats = [
                'total' => Invoice::count(),
                'paid' => Invoice::where('status', 'paid')->count(),
                'pending' => Invoice::where('status', 'pending')->count(),
                'overdue' => Invoice::where('status', 'overdue')->count(),
                'cancelled' => Invoice::where('status', 'cancelled')->count(),
                'total_amount' => Invoice::sum('total'),
                'pending_amount' => Invoice::whereIn('status', ['pending', 'overdue'])->sum('total')
            ];

            // Tickets statistics
            $ticketsStats = [
                'total' => Ticket::count(),
                'open' => Ticket::where('status', 'open')->count(),
                'in_progress' => Ticket::where('status', 'in_progress')->count(),
                'resolved' => Ticket::where('status', 'resolved')->count(),
                'closed' => Ticket::where('status', 'closed')->count(),
                'high_priority' => Ticket::where('priority', 'high')->count(),
                'urgent' => Ticket::where('priority', 'urgent')->count(),
                'avg_response_time' => $this->calculateAvgResponseTime()
            ];

            // Plans and Add-ons statistics
            $plansStats = [
                'total_plans' => ServicePlan::count(),
                'active_plans' => ServicePlan::where('is_active', true)->count(),
                'popular_plans' => ServicePlan::where('is_popular', true)->count(),
                'total_addons' => AddOn::count(),
                'active_addons' => AddOn::where('is_active', true)->count()
            ];

            // Recent activity
            $recentActivity = $this->getRecentActivity();

            return response()->json([
                'success' => true,
                'data' => [
                    'users' => $usersStats,
                    'services' => $servicesStats,
                    'revenue' => $revenueStats,
                    'invoices' => $invoicesStats,
                    'tickets' => $ticketsStats,
                    'plans' => $plansStats,
                    'recent_activity' => $recentActivity
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching dashboard statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all users with advanced filtering and pagination
     */
    public function getUsers(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');
            $status = $request->get('status');
            $role = $request->get('role');
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            $query = User::query();

            // Search filter
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Status filter
            if ($status) {
                $query->where('status', $status);
            }

            // Role filter
            if ($role) {
                $query->where('role', $role);
            }

            // Include related data
            $query->withCount(['services', 'invoices', 'tickets']);

            $users = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $users
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all services with advanced filtering
     */
    public function getServices(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');
            $status = $request->get('status');
            $planId = $request->get('plan_id');
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            $query = Service::with(['user', 'plan']);

            // Search filter
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('domain', 'like', "%{$search}%")
                      ->orWhereHas('user', function($userQuery) use ($search) {
                          $userQuery->where('first_name', 'like', "%{$search}%")
                                   ->orWhere('last_name', 'like', "%{$search}%")
                                   ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            // Status filter
            if ($status) {
                $query->where('status', $status);
            }

            // Plan filter
            if ($planId) {
                $query->where('plan_id', $planId);
            }

            $services = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $services
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching services',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all invoices with admin privileges
     */
    public function getInvoices(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');
            $status = $request->get('status');
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            $query = Invoice::with(['user', 'items']);

            // Search filter
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                      ->orWhereHas('user', function($userQuery) use ($search) {
                          $userQuery->where('first_name', 'like', "%{$search}%")
                                   ->orWhere('last_name', 'like', "%{$search}%")
                                   ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            // Status filter
            if ($status) {
                $query->where('status', $status);
            }

            $invoices = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $invoices
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching invoices',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all tickets with admin privileges
     */
    public function getTickets(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');
            $status = $request->get('status');
            $priority = $request->get('priority');
            $department = $request->get('department');
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            $query = Ticket::with(['user', 'assignedTo', 'service']);

            // Search filter
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('subject', 'like', "%{$search}%")
                      ->orWhere('ticket_number', 'like', "%{$search}%")
                      ->orWhereHas('user', function($userQuery) use ($search) {
                          $userQuery->where('first_name', 'like', "%{$search}%")
                                   ->orWhere('last_name', 'like', "%{$search}%")
                                   ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            // Status filter
            if ($status) {
                $query->where('status', $status);
            }

            // Priority filter
            if ($priority) {
                $query->where('priority', $priority);
            }

            // Department filter
            if ($department) {
                $query->where('department', $department);
            }

            $tickets = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $tickets
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching tickets',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update service status (Admin action)
     */
    public function updateServiceStatus(Request $request, $serviceId)
    {
        try {
            $service = Service::findOrFail($serviceId);
            
            $validated = $request->validate([
                'status' => 'required|in:active,suspended,maintenance,cancelled',
                'reason' => 'nullable|string|max:500'
            ]);

            $service->update([
                'status' => $validated['status'],
                'admin_notes' => $validated['reason'] ?? null
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Service status updated successfully',
                'data' => $service->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating service status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update invoice status (Admin action)
     */
    public function updateInvoiceStatus(Request $request, $invoiceId)
    {
        try {
            $invoice = Invoice::findOrFail($invoiceId);
            
            $validated = $request->validate([
                'status' => 'required|in:pending,paid,overdue,cancelled',
                'notes' => 'nullable|string|max:500'
            ]);

            $invoice->update([
                'status' => $validated['status'],
                'admin_notes' => $validated['notes'] ?? null,
                'paid_at' => $validated['status'] === 'paid' ? now() : null
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice status updated successfully',
                'data' => $invoice->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating invoice status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign ticket to admin user
     */
    public function assignTicket(Request $request, $ticketId)
    {
        try {
            $ticket = Ticket::findOrFail($ticketId);
            
            $validated = $request->validate([
                'assigned_to' => 'nullable|exists:users,id',
                'status' => 'sometimes|in:open,in_progress,resolved,closed'
            ]);

            $ticket->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Ticket assigned successfully',
                'data' => $ticket->fresh(['assignedTo'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error assigning ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ... (rest of the existing methods remain the same)

    /**
     * Create a new user
     */
    public function createUser(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:8',
                'role' => ['required', Rule::in(['admin', 'support', 'client'])],
                'status' => ['required', Rule::in(['active', 'suspended', 'pending_verification'])],
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'country' => 'nullable|string|size:2',
                'postal_code' => 'nullable|string|max:20',
            ]);

            $validated['password'] = Hash::make($validated['password']);
            $validated['uuid'] = \Str::uuid();

            $user = User::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => $user
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user
     */
    public function updateUser(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validated = $request->validate([
                'first_name' => 'sometimes|string|max:100',
                'last_name' => 'sometimes|string|max:100',
                'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
                'password' => 'sometimes|string|min:8',
                'role' => ['sometimes', Rule::in(['admin', 'support', 'client'])],
                'status' => ['sometimes', Rule::in(['active', 'suspended', 'pending_verification', 'banned'])],
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'country' => 'nullable|string|size:2',
                'postal_code' => 'nullable|string|max:20',
            ]);

            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }

            $user->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $user->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user (soft delete)
     */
    public function deleteUser($id)
    {
        try {
            $user = User::findOrFail($id);
            
            // Prevent deletion of super_admin users
            if ($user->role === 'super_admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete super admin users'
                ], 403);
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Helper methods

    private function calculateGrowthRate($model, $dateField)
    {
        $thisMonth = $model::whereMonth($dateField, now()->month)->count();
        $lastMonth = $model::whereMonth($dateField, now()->subMonth()->month)->count();
        
        if ($lastMonth == 0) return $thisMonth > 0 ? 100 : 0;
        
        return round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1);
    }

    private function calculateRevenueGrowthRate()
    {
        $thisMonth = Invoice::where('status', 'paid')
            ->whereMonth('created_at', now()->month)
            ->sum('total');
            
        $lastMonth = Invoice::where('status', 'paid')
            ->whereMonth('created_at', now()->subMonth()->month)
            ->sum('total');
        
        if ($lastMonth == 0) return $thisMonth > 0 ? 100 : 0;
        
        return round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1);
    }

    private function calculateAvgResponseTime()
    {
        // This would calculate based on ticket replies in a real implementation
        return '2.5 hours'; // Mock value
    }

    private function getRecentActivity()
    {
        $activities = [];

        // Recent users
        $recentUsers = User::latest()->take(3)->get();
        foreach ($recentUsers as $user) {
            $activities[] = [
                'type' => 'user_registered',
                'description' => "New user registered: {$user->first_name} {$user->last_name}",
                'time' => $user->created_at->diffForHumans(),
                'icon' => 'user-plus'
            ];
        }

        // Recent services
        $recentServices = Service::latest()->take(3)->get();
        foreach ($recentServices as $service) {
            $activities[] = [
                'type' => 'service_created',
                'description' => "New service created: {$service->name}",
                'time' => $service->created_at->diffForHumans(),
                'icon' => 'server'
            ];
        }

        // Recent tickets
        $recentTickets = Ticket::latest()->take(2)->get();
        foreach ($recentTickets as $ticket) {
            $activities[] = [
                'type' => 'ticket_created',
                'description' => "New ticket: {$ticket->subject}",
                'time' => $ticket->created_at->diffForHumans(),
                'icon' => 'help-circle'
            ];
        }

        // Sort by time and return latest 10
        return collect($activities)->sortByDesc('time')->take(10)->values();
    }

    /**
     * Update user status (activate, suspend, etc.)
     */
    public function updateUserStatus(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            $status = $request->input('status');

            $validStatuses = ['active', 'suspended', 'pending_verification', 'inactive'];
            if (!in_array($status, $validStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid status'
                ], 400);
            }

            $user->status = $status;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'User status updated successfully',
                'data' => $user
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating user status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark invoice as paid
     */
    public function markInvoiceAsPaid(Request $request, $id)
    {
        try {
            $invoice = Invoice::findOrFail($id);
            
            $invoice->status = 'paid';
            $invoice->paid_at = now();
            $invoice->payment_method = $request->input('payment_method', 'manual');
            $invoice->notes = $request->input('notes', 'Marked as paid by administrator');
            $invoice->save();

            return response()->json([
                'success' => true,
                'message' => 'Invoice marked as paid successfully',
                'data' => $invoice
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error marking invoice as paid',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send invoice reminder
     */
    public function sendInvoiceReminder($id)
    {
        try {
            $invoice = Invoice::with('user')->findOrFail($id);
            
            // Here you would implement the actual email sending logic
            // For now, we'll just return success
            
            return response()->json([
                'success' => true,
                'message' => 'Invoice reminder sent successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error sending invoice reminder',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel invoice
     */
    public function cancelInvoice(Request $request, $id)
    {
        try {
            $invoice = Invoice::findOrFail($id);
            
            $invoice->status = 'cancelled';
            $invoice->notes = $request->input('reason', 'Cancelled by administrator');
            $invoice->save();

            return response()->json([
                'success' => true,
                'message' => 'Invoice cancelled successfully',
                'data' => $invoice
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error cancelling invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update ticket status
     */
    public function updateTicketStatus(Request $request, $id)
    {
        try {
            $ticket = Ticket::findOrFail($id);
            $status = $request->input('status');

            $validStatuses = ['open', 'in_progress', 'resolved', 'closed', 'pending'];
            if (!in_array($status, $validStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid status'
                ], 400);
            }

            $ticket->status = $status;
            if ($status === 'closed') {
                $ticket->closed_at = now();
            }
            $ticket->save();

            return response()->json([
                'success' => true,
                'message' => 'Ticket status updated successfully',
                'data' => $ticket
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating ticket status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update ticket priority
     */
    public function updateTicketPriority(Request $request, $id)
    {
        try {
            $ticket = Ticket::findOrFail($id);
            $priority = $request->input('priority');

            $validPriorities = ['low', 'medium', 'high', 'urgent'];
            if (!in_array($priority, $validPriorities)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid priority'
                ], 400);
            }

            $ticket->priority = $priority;
            $ticket->save();

            return response()->json([
                'success' => true,
                'message' => 'Ticket priority updated successfully',
                'data' => $ticket
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating ticket priority',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add reply to ticket
     */
    public function addTicketReply(Request $request, $id)
    {
        try {
            $ticket = Ticket::findOrFail($id);
            
            $request->validate([
                'message' => 'required|string',
                'is_internal' => 'boolean'
            ]);

            $reply = new TicketReply([
                'ticket_id' => $ticket->id,
                'user_id' => auth()->id(),
                'message' => $request->input('message'),
                'is_internal' => $request->input('is_internal', false)
            ]);
            
            $reply->save();

            return response()->json([
                'success' => true,
                'message' => 'Reply added successfully',
                'data' => $reply->load('user')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error adding reply',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get ticket categories
     */
    public function getTicketCategories()
    {
        try {
            // This would typically come from a categories table
            // For now, return hardcoded categories
            $categories = [
                ['id' => 1, 'name' => 'Soporte Técnico'],
                ['id' => 2, 'name' => 'Facturación'],
                ['id' => 3, 'name' => 'Ventas'],
                ['id' => 4, 'name' => 'General']
            ];

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get support agents
     */
    public function getSupportAgents()
    {
        try {
            // Get users with admin or support role
            $agents = User::where('role', 'admin')
                         ->orWhere('role', 'support')
                         ->select('id', 'first_name', 'last_name', 'email')
                         ->get();

            return response()->json([
                'success' => true,
                'data' => $agents
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching agents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new invoice
     */
    public function createInvoice(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'invoice_number' => 'required|string|unique:invoices',
                'amount' => 'required|numeric|min:0',
                'tax_amount' => 'numeric|min:0',
                'total_amount' => 'required|numeric|min:0',
                'due_date' => 'required|date',
                'description' => 'required|string',
                'status' => 'in:pending,paid,overdue,cancelled,draft'
            ]);

            $invoice = Invoice::create([
                'user_id' => $request->user_id,
                'invoice_number' => $request->invoice_number,
                'amount' => $request->amount,
                'tax_amount' => $request->tax_amount ?? 0,
                'total_amount' => $request->total_amount,
                'due_date' => $request->due_date,
                'description' => $request->description,
                'status' => $request->status ?? 'pending',
                'notes' => $request->notes
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => $invoice->load('user')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update invoice
     */
    public function updateInvoice(Request $request, $id)
    {
        try {
            $invoice = Invoice::findOrFail($id);
            
            $request->validate([
                'user_id' => 'exists:users,id',
                'invoice_number' => 'string|unique:invoices,invoice_number,' . $id,
                'amount' => 'numeric|min:0',
                'tax_amount' => 'numeric|min:0',
                'total_amount' => 'numeric|min:0',
                'due_date' => 'date',
                'description' => 'string',
                'status' => 'in:pending,paid,overdue,cancelled,draft'
            ]);

            $invoice->update($request->only([
                'user_id', 'invoice_number', 'amount', 'tax_amount', 
                'total_amount', 'due_date', 'description', 'status', 'notes'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Invoice updated successfully',
                'data' => $invoice->load('user')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete invoice
     */
    public function deleteInvoice($id)
    {
        try {
            $invoice = Invoice::findOrFail($id);
            $invoice->delete();

            return response()->json([
                'success' => true,
                'message' => 'Invoice deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new ticket
     */
    public function createTicket(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'subject' => 'required|string',
                'description' => 'required|string',
                'priority' => 'in:low,medium,high,urgent',
                'status' => 'in:open,in_progress,resolved,closed,pending',
                'assigned_to' => 'nullable|exists:users,id'
            ]);

            $ticket = Ticket::create([
                'user_id' => $request->user_id,
                'subject' => $request->subject,
                'description' => $request->description,
                'priority' => $request->priority ?? 'medium',
                'status' => $request->status ?? 'open',
                'assigned_to' => $request->assigned_to,
                'ticket_number' => 'TKT-' . strtoupper(Str::random(8))
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ticket created successfully',
                'data' => $ticket->load(['user', 'assignedTo'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update ticket
     */
    public function updateTicket(Request $request, $id)
    {
        try {
            $ticket = Ticket::findOrFail($id);
            
            $request->validate([
                'user_id' => 'exists:users,id',
                'subject' => 'string',
                'description' => 'string',
                'priority' => 'in:low,medium,high,urgent',
                'status' => 'in:open,in_progress,resolved,closed,pending',
                'assigned_to' => 'nullable|exists:users,id'
            ]);

            $ticket->update($request->only([
                'user_id', 'subject', 'description', 'priority', 'status', 'assigned_to'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Ticket updated successfully',
                'data' => $ticket->load(['user', 'assignedTo'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete ticket
     */
    public function deleteTicket($id)
    {
        try {
            $ticket = Ticket::findOrFail($id);
            $ticket->delete();

            return response()->json([
                'success' => true,
                'message' => 'Ticket deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

     /**
     * Get a single service with details (Admin)
     */
    public function getService($id)
    {
        try {
            $service = Service::with(["user", "plan.category", "plan.features", "plan.pricing.billingCycle", "invoices", "tickets"])->findOrFail($id);

            return response()->json([
                "success" => true,
                "data" => $service
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Service not found",
                "error" => $e->getMessage()
            ], 404);
        }
    }
}