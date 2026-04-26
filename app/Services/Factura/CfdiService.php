<?php

namespace App\Services\Factura;

use App\Models\Invoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CfdiService
{
    public function __construct(private readonly FacturamaService $facturama) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Timbrado
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Timbra un ServiceInvoice ante el SAT vía Facturama.
     *
     * Si tiene éxito actualiza el registro con cfdi_status = 'stamped' y guarda
     * el UUID SAT, XML, ruta del PDF y el ID interno de Facturama.
     *
     * @throws RuntimeException  si el timbrado falla (el estado queda 'failed')
     */
    public function stamp(ServiceInvoice $si): void
    {
        // Necesitamos la factura interna para obtener los importes
        $invoice = $this->resolveInvoice($si);

        if (!$invoice) {
            throw new RuntimeException("No se encontró Invoice para ServiceInvoice #{$si->id}.");
        }

        try {
            $payload  = $this->buildPayload($si, $invoice);
            $response = $this->facturama->createCfdi($payload);

            // Descargar y guardar el PDF
            $pdfPath = null;
            if (!empty($response['Id'])) {
                $pdfPath = $this->savePdf($response['Id'], $si);
            }

            $si->update([
                'facturama_id' => $response['Id']      ?? null,
                'cfdi_uuid'    => $response['Complement']['TaxStamp']['Uuid'] ?? $response['CfdiData']['Complemento']['TimbreFiscalDigital']['UUID'] ?? null,
                'cfdi_xml'     => $this->fetchXml($response['Id'] ?? null),
                'cfdi_pdf_path'=> $pdfPath,
                'cfdi_status'  => ServiceInvoice::CFDI_STAMPED,
                'cfdi_error'   => null,
                'stamped_at'   => now(),
            ]);

            // Notificar al cliente
            $user = $si->service?->user;
            if ($user) {
                $this->notifyStamped($user, $si);
            }

            Log::info('CFDI timbrado exitosamente', [
                'service_invoice_id' => $si->id,
                'cfdi_uuid'          => $si->cfdi_uuid,
                'facturama_id'       => $si->facturama_id,
            ]);
        } catch (\Throwable $e) {
            $si->update([
                'cfdi_status' => ServiceInvoice::CFDI_FAILED,
                'cfdi_error'  => $e->getMessage(),
            ]);

            Log::error('Error al timbrar CFDI', [
                'service_invoice_id' => $si->id,
                'error'              => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cancelación
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cancela un CFDI ya timbrado.
     *
     * @param string $motivo  Código SAT: 01,02,03,04 (default 02 = error sin relación)
     */
    public function cancel(ServiceInvoice $si, string $motivo = '02', ?string $folioSustituto = null): void
    {
        if (!$si->facturama_id) {
            throw new RuntimeException('Este CFDI no tiene ID de Facturama; no se puede cancelar.');
        }

        if ($si->cfdi_status !== ServiceInvoice::CFDI_STAMPED) {
            throw new RuntimeException('Solo se pueden cancelar CFDIs en estado "stamped".');
        }

        $this->facturama->cancelCfdi($si->facturama_id, $motivo, $folioSustituto);

        $si->update([
            'cfdi_status' => ServiceInvoice::CFDI_CANCELLED,
        ]);

        Log::info('CFDI cancelado', [
            'service_invoice_id' => $si->id,
            'facturama_id'       => $si->facturama_id,
            'motivo'             => $motivo,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Descargas
    // ─────────────────────────────────────────────────────────────────────────

    /** Devuelve el contenido binario del PDF. */
    public function getPdfContent(ServiceInvoice $si): string
    {
        // Si ya lo tenemos guardado localmente, lo leemos
        if ($si->cfdi_pdf_path && Storage::exists($si->cfdi_pdf_path)) {
            return Storage::get($si->cfdi_pdf_path);
        }

        if (!$si->facturama_id) {
            throw new RuntimeException('CFDI sin ID de Facturama; no se puede descargar el PDF.');
        }

        return base64_decode($this->facturama->downloadPdfBase64($si->facturama_id));
    }

    /** Devuelve el contenido XML del CFDI. */
    public function getXmlContent(ServiceInvoice $si): string
    {
        if ($si->cfdi_xml) {
            return $si->cfdi_xml;
        }

        if (!$si->facturama_id) {
            throw new RuntimeException('CFDI sin ID de Facturama; no se puede descargar el XML.');
        }

        return base64_decode($this->facturama->downloadXmlBase64($si->facturama_id));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Construcción del payload CFDI 4.0
    // ─────────────────────────────────────────────────────────────────────────

    public function buildPayload(ServiceInvoice $si, Invoice $invoice): array
    {
        $cfg         = config('facturama');
        $taxRate     = (float) $cfg['tasa_iva'];
        $folio       = $si->folio ?? $si->id;
        $items       = $invoice->items()->get();

        // Construir conceptos
        $conceptos = [];
        $totalBase = 0.0;
        $totalIva  = 0.0;

        foreach ($items as $item) {
            $base    = round((float) $item->unit_price * (int) ($item->quantity ?? 1), 2);
            $iva     = round($base * $taxRate, 2);
            $importe = round($base + $iva, 2);

            $totalBase += $base;
            $totalIva  += $iva;

            $conceptos[] = [
                'ClaveProdServ' => $cfg['clave_prod_serv'],
                'ClaveUnidad'   => $cfg['clave_unidad'],
                'Cantidad'      => '1',
                'Unidad'        => $cfg['unidad'],
                'Descripcion'   => mb_substr($item->description, 0, 1000),
                'ValorUnitario' => $base,
                'Importe'       => $base,
                'Descuento'     => 0,
                'ObjetoImp'     => '02',  // Sí objeto de impuesto
                'Impuestos'     => [
                    'Traslados' => [[
                        'Base'         => $base,
                        'Impuesto'     => '002',        // IVA
                        'TipoFactor'   => 'Tasa',
                        'TasaOCuota'   => number_format($taxRate, 6, '.', ''),
                        'Importe'      => $iva,
                    ]],
                ],
            ];
        }

        $subtotal = round($totalBase, 2);
        $ivaTotal = round($totalIva, 2);
        $total    = round($subtotal + $ivaTotal, 2);

        return [
            'Serie'              => $cfg['serie'],
            'Folio'              => (string) $folio,
            'Fecha'              => now()->format('Y-m-d\TH:i:s'),
            'FormaPago'          => $this->resolveFormaPago($invoice),
            'MetodoPago'         => $cfg['metodo_pago'],
            'LugarExpedicion'    => $cfg['issuer']['lugar_expedicion'],
            'Moneda'             => $cfg['moneda'],
            'SubTotal'           => $subtotal,
            'Total'              => $total,
            'TipoDeComprobante'  => $cfg['tipo_comprobante'],
            'Emisor' => [
                'Rfc'            => strtoupper($cfg['issuer']['rfc']),
                'Nombre'         => strtoupper($cfg['issuer']['name']),
                'RegimenFiscal'  => $cfg['issuer']['regimen_fiscal'],
            ],
            'Receptor' => [
                'Rfc'                      => strtoupper($si->rfc),
                'Nombre'                   => strtoupper($si->name),
                'DomicilioFiscalReceptor'  => $si->zip,
                'RegimenFiscalReceptor'    => $si->regimen,
                'UsoCfdi'                  => $si->uso_cfdi,
            ],
            'Conceptos' => $conceptos,
            'Impuestos' => [
                'TotalImpuestosTrasladados' => $ivaTotal,
                'Traslados' => [[
                    'Base'         => $subtotal,
                    'Impuesto'     => '002',
                    'TipoFactor'   => 'Tasa',
                    'TasaOCuota'   => number_format($taxRate, 6, '.', ''),
                    'Importe'      => $ivaTotal,
                ]],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveInvoice(ServiceInvoice $si): ?Invoice
    {
        // Primero por FK directa (si ya existe el campo)
        if ($si->invoice_id) {
            return Invoice::find($si->invoice_id);
        }

        // Fallback: invoice más reciente del mismo servicio
        return Invoice::where('service_id', $si->service_id)
            ->latest()
            ->first();
    }

    /**
     * Mapea el método de pago de Stripe/sistema al código SAT de FormaPago.
     *
     * Códigos SAT más comunes:
     *  01 = Efectivo
     *  02 = Cheque nominativo
     *  03 = Transferencia electrónica
     *  04 = Tarjeta de crédito
     *  28 = Tarjeta de débito
     *  99 = Por definir (cuando no se sabe al momento de emitir)
     */
    private function resolveFormaPago(Invoice $invoice): string
    {
        // Intentar mapear desde el método de pago registrado
        $method = strtolower($invoice->payment_method ?? '');

        return match (true) {
            str_contains($method, 'credit') => '04',
            str_contains($method, 'debit')  => '28',
            str_contains($method, 'stripe') => '04',  // Stripe default = tarjeta
            str_contains($method, 'transfer') => '03',
            str_contains($method, 'cash')   => '01',
            default                         => config('facturama.forma_pago', '03'),
        };
    }

    private function savePdf(string $facturamaId, ServiceInvoice $si): ?string
    {
        try {
            $content  = base64_decode($this->facturama->downloadPdfBase64($facturamaId));
            $path     = "cfdis/{$si->service_id}/{$si->id}.pdf";
            Storage::put($path, $content);
            return $path;
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar el PDF del CFDI', [
                'service_invoice_id' => $si->id,
                'error'              => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fetchXml(?string $facturamaId): ?string
    {
        if (!$facturamaId) return null;

        try {
            return base64_decode($this->facturama->downloadXmlBase64($facturamaId));
        } catch (\Throwable $e) {
            Log::warning('No se pudo descargar el XML del CFDI', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function notifyStamped(User $user, ServiceInvoice $si): void
    {
        try {
            Notification::send($user, new \App\Notifications\ServiceNotification([
                'title'   => 'Tu factura está lista',
                'message' => 'Tu CFDI ha sido timbrado exitosamente. Ya puedes descargarlo desde tu portal.',
                'type'    => 'invoice.stamped',
                'data'    => [
                    'service_invoice_id' => $si->id,
                    'cfdi_uuid'          => $si->cfdi_uuid,
                ],
            ]));
        } catch (\Throwable $e) {
            Log::warning('No se pudo notificar al usuario sobre CFDI timbrado', ['error' => $e->getMessage()]);
        }
    }
}
