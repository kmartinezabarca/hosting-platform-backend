<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoiceService)
    {
    }

    /**
     * Get user's invoices
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $query = Invoice::where('user_id', $user->id);

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

            $perPage = min((int) $request->get('per_page', 15), 100);

            $invoices = $query->with('items')
                            ->orderBy('created_at', 'desc')
                            ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $invoices
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving invoices',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get a specific invoice
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $user = Auth::user();
            $invoice = Invoice::where('uuid', $uuid)
                            ->where('user_id', $user->id)
                            ->with(['items', 'user'])
                            ->first();

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving invoice',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Create a new invoice (Admin only)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'items' => 'required|array|min:1',
                'items.*.description' => 'required|string',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.unit_price' => 'required|numeric|min:0',
                'tax_rate' => 'nullable|numeric|min:0|max:100',
                'due_date' => 'required|date|after:today',
                'notes' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Calculate totals
            $subtotal = 0;
            foreach ($request->items as $item) {
                $subtotal += $item['quantity'] * $item['unit_price'];
            }

            $taxRate   = (float) $request->get('tax_rate', 0);
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $total     = round($subtotal + $taxAmount, 2);

            $invoice = $this->invoiceService->createWithItems(
                [
                    'user_id'   => Auth::id(),
                    'subtotal'  => $subtotal,
                    'tax_rate'  => $taxRate,
                    'tax_amount'=> $taxAmount,
                    'total'     => $total,
                    'due_date'  => $request->due_date,
                    'notes'     => $request->notes,
                    'status'    => 'draft',
                ],
                collect($request->items)->map(fn($i) => [
                    'description' => $i['description'],
                    'quantity'    => $i['quantity'],
                    'unit_price'  => $i['unit_price'],
                ])->all()
            );

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => $invoice
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating invoice',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update invoice status
     */
    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:draft,sent,paid,overdue,cancelled,refunded',
                'payment_method' => 'nullable|string',
                'payment_reference' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user    = Auth::user();
            $invoice = Invoice::where('uuid', $uuid)->where('user_id', $user->id)->first();

            if (!$invoice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found'
                ], 404);
            }

            $updateData = ['status' => $request->status];

            if ($request->status === 'paid') {
                $updateData['paid_at'] = now();
                if ($request->has('payment_method')) {
                    $updateData['payment_method'] = $request->payment_method;
                }
                if ($request->has('payment_reference')) {
                    $updateData['payment_reference'] = $request->payment_reference;
                }
            }

            $invoice->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Invoice status updated successfully',
                'data' => $invoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating invoice status',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get invoice statistics
     */
    public function getStats(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->invoiceService->getStatsForUser(Auth::id()),
        ]);
    }
}

