<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

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

            $tickets = $query->with(['assignedTo', 'service'])
                           ->orderBy('created_at', 'desc')
                           ->paginate($request->get('per_page', 15));

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
        try {
            $validator = Validator::make($request->all(), [
                'subject' => 'required|string|max:500',
                'message' => 'required|string',
                'priority' => 'required|in:low,medium,high,urgent',
                'department' => 'required|in:technical,billing,sales,abuse',
                'service_id' => 'nullable|exists:services,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();

            // Generate ticket number
            $ticketNumber = $this->generateTicketNumber();

            // Create ticket
            $ticket = Ticket::create([
                'user_id' => $user->id,
                'service_id' => $request->service_id,
                'ticket_number' => $ticketNumber,
                'subject' => $request->subject,
                'priority' => $request->priority,
                'department' => $request->department
            ]);

            // Create initial reply with the message
            TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $request->message,
                'is_staff_reply' => false
            ]);

            $ticket->load(['assignedTo', 'service', 'replies.user']);

            return response()->json([
                'success' => true,
                'message' => 'Ticket created successfully',
                'data' => $ticket
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating ticket',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add a reply to a ticket
     */
    public function addReply(Request $request, string $uuid): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'message' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

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

            // Check if ticket is closed
            if ($ticket->status === 'closed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot reply to a closed ticket'
                ], 400);
            }

            // Create reply
            $reply = TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $request->message,
                'is_staff_reply' => false
            ]);

            // Update ticket status if it was resolved
            if ($ticket->status === 'resolved') {
                $ticket->update(['status' => 'open']);
            }

            $reply->load('user');

            return response()->json([
                'success' => true,
                'message' => 'Reply added successfully',
                'data' => $reply
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error adding reply',
                'error' => $e->getMessage()
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

