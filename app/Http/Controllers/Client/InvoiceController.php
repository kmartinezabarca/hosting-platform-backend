<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\Factura\CfdiService;
use App\Services\PaymentReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly CfdiService $cfdi,
        private readonly PaymentReceiptService $receiptService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $user    = Auth::user();
            $query   = Receipt::where('user_id', $user->id);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $perPage  = min((int) $request->get('per_page', 15), 100);
            $receipts = $query->with(['items', 'invoice'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage)
                ->through(fn (Receipt $r) => $this->withCfdi($r));

            return response()->json(['success' => true, 'data' => $receipts]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener facturas', 'debug' => config('app.debug') ? $e->getMessage() : null], 500);
        }
    }

    /**
     * GET /api/invoices/cfdi
     * Lista SOLO las facturas fiscales (CFDI) del usuario, con su folio
     * fiscal propio. Para descargar XML/PDF se usa el uuid del recibo
     * ligado (los endpoints existentes resuelven el CFDI internamente).
     */
    public function cfdi(Request $request): JsonResponse
    {
        try {
            $userId = Auth::id();
            $serie  = config('facturama.serie', 'F');

            $query = Invoice::whereHas('receipt', fn ($q) => $q->where('user_id', $userId))
                ->with(['receipt:id,uuid,invoice_number,total,currency,status,paid_at,created_at',
                        'receipt.items']);

            if ($request->filled('status')) {
                $query->where('cfdi_status', $request->status);
            }

            $perPage = min((int) $request->get('per_page', 15), 100);
            $page    = $query->orderByDesc('id')->paginate($perPage)->through(function (Invoice $inv) use ($serie) {
                $folio = $inv->folio ?? $inv->id;
                $r     = $inv->receipt;

                return [
                    'id'                 => $inv->id,
                    'uuid'               => $r?->uuid,                 // para descargar (pdf/xml/receipt)
                    'receipt_number'     => $r?->invoice_number,       // REC-...
                    'fiscal_number'      => "{$serie}-{$folio}",       // serie + folio
                    'serie'              => $serie,
                    'folio'              => (string) $folio,
                    'cfdi_uuid'          => $inv->cfdi_uuid,           // folio fiscal SAT
                    'cfdi_status'        => $inv->cfdi_status,
                    'cfdi_error'         => $inv->cfdi_error,
                    'is_publico_general' => (bool) $inv->is_publico_general,
                    'rfc'                => $inv->rfc,
                    'receptor'           => $inv->name,
                    'stamp_scheduled_at' => optional($inv->stamp_scheduled_at)->toISOString(),
                    'stamped_at'         => optional($inv->stamped_at)->toISOString(),
                    'total'              => $r?->total,
                    'currency'           => $r?->currency ?? 'MXN',
                    'status'             => $r?->status,
                    'items'              => $r?->items,
                    'created_at'         => optional($inv->created_at)->toISOString(),
                ];
            });

            return response()->json(['success' => true, 'data' => $page]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener facturas', 'debug' => config('app.debug') ? $e->getMessage() : null], 500);
        }
    }

    public function show(string $uuid): JsonResponse
    {
        try {
            $user    = Auth::user();
            $receipt = Receipt::where('uuid', $uuid)->where('user_id', $user->id)->with(['items', 'user', 'invoice'])->first();

            if (!$receipt) {
                return response()->json(['success' => false, 'message' => 'Factura no encontrada'], 404);
            }

            return response()->json(['success' => true, 'data' => $this->withCfdi($receipt)]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener el comprobante', 'debug' => config('app.debug') ? $e->getMessage() : null], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'items'               => 'required|array|min:1',
                'items.*.description' => 'required|string',
                'items.*.quantity'    => 'required|integer|min:1',
                'items.*.unit_price'  => 'required|numeric|min:0',
                'tax_rate'            => 'nullable|numeric|min:0|max:100',
                'due_date'            => 'required|date|after:today',
                'notes'               => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            $subtotal  = collect($request->items)->sum(fn($i) => $i['quantity'] * $i['unit_price']);
            $taxRate   = (float) $request->get('tax_rate', 0);
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $total     = round($subtotal + $taxAmount, 2);

            $receipt = $this->invoiceService->createWithItems(
                ['user_id' => Auth::id(), 'subtotal' => $subtotal, 'tax_rate' => $taxRate, 'tax_amount' => $taxAmount, 'total' => $total, 'due_date' => $request->due_date, 'notes' => $request->notes, 'status' => 'draft'],
                collect($request->items)->map(fn($i) => ['description' => $i['description'], 'quantity' => $i['quantity'], 'unit_price' => $i['unit_price']])->all()
            );

            return response()->json(['success' => true, 'message' => 'Comprobante creado', 'data' => $receipt], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al crear el comprobante', 'debug' => config('app.debug') ? $e->getMessage() : null], 500);
        }
    }

    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status'            => 'required|in:draft,sent,paid,overdue,cancelled,refunded',
                'payment_method'    => 'nullable|string',
                'payment_reference' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            $receipt = Receipt::where('uuid', $uuid)->where('user_id', Auth::id())->first();

            if (!$receipt) {
                return response()->json(['success' => false, 'message' => 'Comprobante no encontrado'], 404);
            }

            $updateData = ['status' => $request->status];
            if ($request->status === 'paid') {
                $updateData['paid_at'] = now();
                if ($request->has('payment_method'))    $updateData['payment_method']    = $request->payment_method;
                if ($request->has('payment_reference')) $updateData['payment_reference'] = $request->payment_reference;
            }

            $receipt->update($updateData);

            return response()->json(['success' => true, 'message' => 'Estado actualizado', 'data' => $receipt]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar', 'debug' => config('app.debug') ? $e->getMessage() : null], 500);
        }
    }

    /**
     * GET /api/invoices/{uuid}/pdf
     * Descarga el PDF del CFDI (factura SAT) vinculado al comprobante.
     */
    public function downloadPdf(string $uuid): Response|JsonResponse
    {
        $receipt = Receipt::where('uuid', $uuid)->where('user_id', Auth::id())->firstOrFail();
        $inv     = $this->resolveInvoice($receipt);

        if (!$inv || $inv->cfdi_status !== Invoice::CFDI_STAMPED) {
            return response()->json(['success' => false, 'message' => 'El CFDI aún no está disponible.', 'status' => $inv?->cfdi_status ?? 'not_found'], 404);
        }

        try {
            $content = $this->cfdi->getPdfContent($inv);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"factura-{$receipt->uuid}.pdf\"",
        ]);
    }

    /**
     * GET /api/invoices/{uuid}/xml
     * Descarga el XML del CFDI vinculado al comprobante.
     */
    public function downloadXml(string $uuid): Response|JsonResponse
    {
        $receipt = Receipt::where('uuid', $uuid)->where('user_id', Auth::id())->firstOrFail();
        $inv     = $this->resolveInvoice($receipt);

        if (!$inv || $inv->cfdi_status !== Invoice::CFDI_STAMPED) {
            return response()->json(['success' => false, 'message' => 'El CFDI aún no está disponible.', 'status' => $inv?->cfdi_status ?? 'not_found'], 404);
        }

        try {
            $content = $this->cfdi->getXmlContent($inv);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response($content, 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"factura-{$receipt->uuid}.xml\"",
        ]);
    }

    /**
     * PUT /api/invoices/{uuid}/fiscal-data
     * Actualiza datos fiscales del cliente dentro de la ventana de 72 h.
     */
    public function updateFiscalData(Request $request, string $uuid): JsonResponse
    {
        $receipt = Receipt::where('uuid', $uuid)->where('user_id', Auth::id())->firstOrFail();
        $inv     = $this->resolveInvoice($receipt);

        if (!$inv) {
            return response()->json(['success' => false, 'message' => 'Comprobante sin datos CFDI.'], 404);
        }

        if ($inv->cfdi_status === Invoice::CFDI_STAMPED) {
            return response()->json(['success' => false, 'message' => 'Esta factura ya fue timbrada y no se puede modificar.'], 422);
        }

        if ($inv->cfdi_status === Invoice::CFDI_CANCELLED) {
            return response()->json(['success' => false, 'message' => 'Esta factura fue cancelada.'], 422);
        }

        $validated = $request->validate([
            'rfc'           => ['required', 'string', 'min:12', 'max:13', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/i'],
            'business_name'      => ['required', 'string', 'max:255'],
            'postal_code'        => ['required', 'string', 'digits:5'],
            'fiscal_regime_code' => ['required', 'string', 'exists:fiscal_regimes,code'],
            'cfdi_use_code'      => ['required', 'string', 'exists:cfdi_uses,code'],
        ], ['rfc.regex' => 'RFC format is invalid.']);

        $inv->update([
            'rfc'                => strtoupper(trim($validated['rfc'])),
            'name'               => strtoupper(trim($validated['business_name'])),
            'zip'                => $validated['postal_code'],
            'regimen'            => $validated['fiscal_regime_code'],
            'cfdi_use_code'      => $validated['cfdi_use_code'],
            'cfdi_status'        => Invoice::CFDI_PENDING_STAMP,
            'is_publico_general' => false,
            'stamp_scheduled_at' => null,
        ]);

        try {
            $this->cfdi->stamp($inv->fresh());
            return response()->json(['success' => true, 'message' => '¡Datos guardados y factura timbrada exitosamente!', 'data' => $inv->fresh()]);
        } catch (\Throwable $e) {
            Log::error('Timbrado tras actualización de datos fiscales falló', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'message' => 'Datos guardados. El timbrado se reintentará automáticamente.', 'data' => $inv->fresh()]);
        }
    }

    /**
     * GET /api/invoices/{uuid}/receipt
     * Descarga el PDF del comprobante de pago interno.
     */
    public function downloadReceipt(string $uuid): Response|JsonResponse
    {
        $receipt = Receipt::where('uuid', $uuid)
            ->where('user_id', Auth::id())
            ->with(['user', 'items', 'service.plan'])
            ->firstOrFail();

        $content = $this->receiptService->getContent($receipt);

        if (!$content) {
            return response()->json(['success' => false, 'message' => 'No se pudo generar el comprobante de pago.'], 500);
        }

        return response($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"comprobante-{$receipt->invoice_number}.pdf\"",
        ]);
    }

    public function getStats(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->invoiceService->getStatsForUser(Auth::id())]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function resolveInvoice(Receipt $receipt): ?Invoice
    {
        $inv = Invoice::where('invoice_id', $receipt->id)->first();
        if ($inv) return $inv;

        if ($receipt->service_id) {
            return Invoice::where('service_id', $receipt->service_id)->latest()->first();
        }

        return null;
    }

    /**
     * Serializa el Receipt incluyendo el estado de su factura CFDI fiscal
     * para que el frontend pueda mostrar el folio fiscal y los botones de
     * descarga (Comprobante / XML / PDF).
     */
    private function withCfdi(Receipt $receipt): array
    {
        $data = $receipt->toArray();

        // El Receipt tiene hasMany Invoice (CFDI); tomamos el más reciente.
        $cfdi = $receipt->relationLoaded('invoice')
            ? $receipt->invoice->sortByDesc('id')->first()
            : Invoice::where('invoice_id', $receipt->id)->latest('id')->first();

        if (!$cfdi) {
            // Servicios gratis / $0 no generan CFDI.
            $data['cfdi_status'] = 'not_required';
            return $data;
        }

        $data['cfdi_status']        = $cfdi->cfdi_status;       // scheduled|pending_stamp|stamped|failed|cancelled
        $data['cfdi_uuid']          = $cfdi->cfdi_uuid;         // folio fiscal SAT
        $data['cfdi_folio']         = $cfdi->folio;
        $data['cfdi_error']         = $cfdi->cfdi_error;
        $data['stamp_scheduled_at'] = optional($cfdi->stamp_scheduled_at)->toISOString();
        $data['stamped_at']         = optional($cfdi->stamped_at)->toISOString();
        $data['is_publico_general'] = (bool) $cfdi->is_publico_general;

        return $data;
    }
}
