<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    /**
     * Get user's transactions
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $query = Transaction::where('user_id', $user->id);

            // Filter by type if provided
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by date range if provided
            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $transactions = $query->with(['invoice', 'paymentMethod'])
                                ->orderBy('created_at', 'desc')
                                ->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific transaction
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $user = Auth::user();
            $transaction = Transaction::where('uuid', $uuid)
                                   ->where('user_id', $user->id)
                                   ->with(['invoice', 'paymentMethod', 'user'])
                                   ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $transaction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving transaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get transaction statistics
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $stats = [
                'total_transactions' => Transaction::where('user_id', $user->id)->count(),
                'completed_transactions' => Transaction::where('user_id', $user->id)->where('status', 'completed')->count(),
                'pending_transactions' => Transaction::where('user_id', $user->id)->where('status', 'pending')->count(),
                'failed_transactions' => Transaction::where('user_id', $user->id)->where('status', 'failed')->count(),
                'total_amount' => Transaction::where('user_id', $user->id)->where('status', 'completed')->sum('amount'),
                'total_fees' => Transaction::where('user_id', $user->id)->where('status', 'completed')->sum('fee_amount'),
                'payments_amount' => Transaction::where('user_id', $user->id)
                                              ->where('type', 'payment')
                                              ->where('status', 'completed')
                                              ->sum('amount'),
                'refunds_amount' => Transaction::where('user_id', $user->id)
                                              ->where('type', 'refund')
                                              ->where('status', 'completed')
                                              ->sum('amount')
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving transaction statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent transactions
     */
    public function getRecent(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $limit = $request->get('limit', 10);

            $transactions = Transaction::where('user_id', $user->id)
                                     ->with(['invoice', 'paymentMethod'])
                                     ->orderBy('created_at', 'desc')
                                     ->limit($limit)
                                     ->get();

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving recent transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

