<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
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

            $invoices = $query->with('items')
                            ->orderBy('created_at', 'desc')
                            ->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $invoices
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving invoices',
                'error' => $e->getMessage()
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
                'error' => $e->getMessage()
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
                'user_id' => 'required|exists:users,id',
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

            DB::beginTransaction();

            // Calculate totals
            $subtotal = 0;
            foreach ($request->items as $item) {
                $subtotal += $item['quantity'] * $item['unit_price'];
            }

            $taxRate = $request->get('tax_rate', 0);
            $taxAmount = ($subtotal * $taxRate) / 100;
            $total = $subtotal + $taxAmount;

            // Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber();

            // Create invoice
            $invoice = Invoice::create([
                'user_id' => $request->user_id,
                'invoice_number' => $invoiceNumber,
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'due_date' => $request->due_date,
                'notes' => $request->notes
            ]);

            // Create invoice items
            foreach ($request->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => $item['quantity'] * $item['unit_price']
                ]);
            }

            DB::commit();

            $invoice->load('items');

            return response()->json([
                'success' => true,
                'message' => 'Invoice created successfully',
                'data' => $invoice
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating invoice',
                'error' => $e->getMessage()
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

            $invoice = Invoice::where('uuid', $uuid)->first();

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
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get invoice statistics
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $stats = [
                'total_invoices' => Invoice::where('user_id', $user->id)->count(),
                'paid_invoices' => Invoice::where('user_id', $user->id)->where('status', 'paid')->count(),
                'pending_invoices' => Invoice::where('user_id', $user->id)->whereIn('status', ['draft', 'sent'])->count(),
                'overdue_invoices' => Invoice::where('user_id', $user->id)->where('status', 'overdue')->count(),
                'total_amount' => Invoice::where('user_id', $user->id)->sum('total'),
                'paid_amount' => Invoice::where('user_id', $user->id)->where('status', 'paid')->sum('total'),
                'pending_amount' => Invoice::where('user_id', $user->id)->whereIn('status', ['draft', 'sent', 'overdue'])->sum('total')
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving invoice statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate unique invoice number
     */
    private function generateInvoiceNumber(): string
    {
        $prefix = config('app.invoice_prefix', 'INV-');
        $year = date('Y');
        $month = date('m');
        
        // Get the last invoice number for this month
        $lastInvoice = Invoice::where('invoice_number', 'like', $prefix . $year . $month . '%')
                            ->orderBy('invoice_number', 'desc')
                            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix . $year . $month . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}

