<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceInvoice;
use App\Services\InvoiceService;
use App\Services\Factura\CfdiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly CfdiService $cfdi,
    ) {}

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
     * GET /api/invoices/{uuid}/pdf
     * Descarga el PDF del CFDI asociado a una factura.
     */
    public function downloadPdf(string $uuid): Response|JsonResponse
    {
        $invoice = Invoice::where('uuid', $uuid)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $si = $this->resolveServiceInvoice($invoice);

        if (!$si || $si->cfdi_status !== ServiceInvoice::CFDI_STAMPED) {
            return response()->json([
                'success' => false,
                'message' => 'El CFDI de esta factura aún no está disponible.',
                'status'  => $si?->cfdi_status ?? 'not_found',
            ], 404);
        }

        try {
            $content = $this->cfdi->getPdfContent($si);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"factura-{$invoice->uuid}.pdf\"",
        ]);
    }

    /**
     * GET /api/invoices/{uuid}/xml
     * Descarga el XML del CFDI asociado a una factura.
     */
    public function downloadXml(string $uuid): Response|JsonResponse
    {
        $invoice = Invoice::where('uuid', $uuid)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $si = $this->resolveServiceInvoice($invoice);

        if (!$si || $si->cfdi_status !== ServiceInvoice::CFDI_STAMPED) {
            return response()->json([
                'success' => false,
                'message' => 'El CFDI de esta factura aún no está disponible.',
                'status'  => $si?->cfdi_status ?? 'not_found',
            ], 404);
        }

        try {
            $content = $this->cfdi->getXmlContent($si);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response($content, 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"factura-{$invoice->uuid}.xml\"",
        ]);
    }

    /**
     * PUT /api/invoices/{uuid}/fiscal-data
     * Permite al cliente actualizar sus datos fiscales ANTES del timbrado (plazo 72 h).
     * Si el CFDI estaba en 'scheduled' o 'needs_info', lo mueve a pending_stamp y
     * lo timbra inmediatamente.
     */
    public function updateFiscalData(Request $request, string $uuid): JsonResponse
    {
        $invoice = Invoice::where('uuid', $uuid)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $si = $this->resolveServiceInvoice($invoice);

        if (!$si) {
            return response()->json(['success' => false, 'message' => 'Factura sin datos CFDI.'], 404);
        }

        if ($si->cfdi_status === ServiceInvoice::CFDI_STAMPED) {
            return response()->json([
                'success' => false,
                'message' => 'Esta factura ya fue timbrada y no se puede modificar.',
            ], 422);
        }

        if ($si->cfdi_status === ServiceInvoice::CFDI_CANCELLED) {
            return response()->json([
                'success' => false,
                'message' => 'Esta factura fue cancelada.',
            ], 422);
        }

        $validated = $request->validate([
            'rfc'            => ['required', 'string', 'min:12', 'max:13', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/i'],
            'razon_social'   => ['required', 'string', 'max:255'],
            'codigo_postal'  => ['required', 'string', 'digits:5'],
            'regimen_fiscal' => ['required', 'string', 'exists:fiscal_regimes,code'],
            'uso_cfdi'       => ['required', 'string', 'exists:cfdi_uses,code'],
        ], ['rfc.regex' => 'El RFC no tiene formato válido.']);

        $si->update([
            'rfc'                => strtoupper(trim($validated['rfc'])),
            'name'               => strtoupper(trim($validated['razon_social'])),
            'zip'                => $validated['codigo_postal'],
            'regimen'            => $validated['regimen_fiscal'],
            'uso_cfdi'           => $validated['uso_cfdi'],
            'cfdi_status'        => ServiceInvoice::CFDI_PENDING_STAMP,
            'is_publico_general' => false,
            'stamp_scheduled_at' => null,
        ]);

        // Intentar timbrar inmediatamente
        try {
            $this->cfdi->stamp($si->fresh());
            return response()->json([
                'success' => true,
                'message' => '¡Datos guardados y factura timbrada exitosamente!',
                'data'    => $si->fresh(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Timbrado tras actualización de datos fiscales falló', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => true, // datos guardados aunque el timbrado falló
                'message' => 'Datos guardados. El timbrado se reintentará automáticamente.',
                'data'    => $si->fresh(),
            ]);
        }
    }

    // ── Helper privado ────────────────────────────────────────────────────────

    private function resolveServiceInvoice(Invoice $invoice): ?ServiceInvoice
    {
        // Por FK directa
        $si = ServiceInvoice::where('invoice_id', $invoice->id)->first();
        if ($si) return $si;

        // Fallback por service_id
        if ($invoice->service_id) {
            return ServiceInvoice::where('service_id', $invoice->service_id)->latest()->first();
        }

        return null;
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

