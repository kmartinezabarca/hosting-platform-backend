<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Factura\CfdiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CfdiController extends Controller
{
    public function __construct(private readonly CfdiService $cfdi) {}

    /**
     * GET /admin/cfdi
     * Lista ServiceInvoices con filtros por estado.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status'     => ['sometimes', 'string', Rule::in(['scheduled', 'pending_stamp', 'stamped', 'failed', 'cancelled'])],
            'rfc'        => ['sometimes', 'string', 'max:13'],
            'service_id' => ['sometimes', 'integer', 'min:1'],
            'per_page'   => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Invoice::with(['service.user:id,uuid,first_name,last_name,email'])
            ->when(! empty($filters['status']),     fn($q) => $q->where('cfdi_status', $filters['status']))
            ->when(! empty($filters['rfc']),        fn($q) => $q->where('rfc', 'like', '%' . $filters['rfc'] . '%'))
            ->when(! empty($filters['service_id']), fn($q) => $q->where('service_id', $filters['service_id']))
            ->orderByDesc('created_at');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate((int) ($filters['per_page'] ?? 20)),
        ]);
    }

    /**
     * GET /admin/cfdi/stats
     * Resumen de estados CFDI.
     */
    public function stats(): JsonResponse
    {
        $statuses = ['scheduled', 'pending_stamp', 'stamped', 'failed', 'cancelled'];

        $counts = Invoice::selectRaw('cfdi_status, COUNT(*) as total')
            ->groupBy('cfdi_status')
            ->pluck('total', 'cfdi_status');

        return response()->json([
            'success' => true,
            'data'    => collect($statuses)->mapWithKeys(fn($s) => [$s => (int) ($counts[$s] ?? 0)]),
        ]);
    }

    /**
     * GET /admin/cfdi/{id}
     * Detalle de un ServiceInvoice.
     */
    public function show(int $id): JsonResponse
    {
        $si = Invoice::with([
            'service.user',
            'service.plan',
            'receipt.items',
        ])->findOrFail($id);

        // Fallback: si invoice_id está vacío, buscar el receipt por service_id
        if (!$si->receipt && $si->service_id) {
            $receipt = \App\Models\Receipt::where('service_id', $si->service_id)
                ->with('items')
                ->latest()
                ->first();
            $si->setRelation('receipt', $receipt);
        }

        // Adjuntar config del emisor para facilitar la facturación manual
        $si->emisor = [
            'rfc'             => config('facturama.issuer.rfc'),
            'nombre'          => config('facturama.issuer.name'),
            'fiscal_regime'   => config('facturama.issuer.fiscal_regime'),
            'lugar_expedicion'=> config('facturama.issuer.lugar_expedicion'),
            'serie'           => config('facturama.serie'),
            'metodo_pago'     => config('facturama.metodo_pago'),
            'forma_pago'      => config('facturama.forma_pago'),
            'moneda'          => config('facturama.moneda'),
            'tasa_iva'        => config('facturama.tasa_iva'),
        ];

        return response()->json(['success' => true, 'data' => $si]);
    }

    /**
     * POST /admin/cfdi/{id}/retry
     * Reintenta el timbrado de un CFDI fallido o pendiente.
     */
    public function retry(int $id): JsonResponse
    {
        $si = Invoice::findOrFail($id);

        if (!in_array($si->cfdi_status, [Invoice::CFDI_FAILED, Invoice::CFDI_PENDING_STAMP, Invoice::CFDI_SCHEDULED])) {
            return response()->json([
                'success' => false,
                'message' => "No se puede timbrar un CFDI en estado '{$si->cfdi_status}'.",
            ], 422);
        }

        try {
            $this->cfdi->stamp($si);
            return response()->json([
                'success' => true,
                'message' => 'CFDI timbrado exitosamente.',
                'data'    => $si->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al timbrar: ' . $e->getMessage(),
                'data'    => $si->fresh(),
            ], 500);
        }
    }

    /**
     * POST /admin/cfdi/{id}/cancel
     * Cancela un CFDI timbrado ante el SAT.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $si = Invoice::findOrFail($id);

        $validated = $request->validate([
            'motivo'           => ['required', Rule::in(['01', '02', '03', '04'])],
            'folio_sustituto'  => ['nullable', 'string', 'size:36'],  // UUID del CFDI sustituto (motivo 01)
        ], [
            'motivo.in' => 'Motivo inválido. Use: 01 (error con relación), 02 (error sin relación), 03 (no se realizó), 04 (operación nominativa).',
        ]);

        try {
            $this->cfdi->cancel($si, $validated['motivo'], $validated['folio_sustituto'] ?? null);
            return response()->json([
                'success' => true,
                'message' => 'CFDI cancelado.',
                'data'    => $si->fresh(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cancelar: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /admin/cfdi/{id}/download/{format}
     * Descarga el PDF o XML de un CFDI. format = pdf | xml
     */
    public function download(int $id, string $format): Response
    {
        $si = Invoice::findOrFail($id);

        if ($si->cfdi_status !== Invoice::CFDI_STAMPED) {
            abort(422, 'El CFDI aún no ha sido timbrado.');
        }

        if ($format === 'pdf') {
            $content  = $this->cfdi->getPdfContent($si);
            $filename = "cfdi-{$si->id}.pdf";
            $mime     = 'application/pdf';
        } elseif ($format === 'xml') {
            $content  = $this->cfdi->getXmlContent($si);
            $filename = "cfdi-{$si->id}.xml";
            $mime     = 'application/xml';
        } else {
            abort(400, 'Formato inválido. Use: pdf | xml');
        }

        return response($content, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
