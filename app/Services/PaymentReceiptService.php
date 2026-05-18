<?php

namespace App\Services;

use App\Models\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PaymentReceiptService
{
    private const DISK = 'local';
    private const DIR  = 'receipts';

    /**
     * Generate a PDF payment receipt for the given Receipt,
     * store it on disk, and update Receipt.pdf_path.
     *
     * Returns the stored path (relative to the disk root) or null on failure.
     */
    public function generate(Receipt $invoice): ?string
    {
        try {
            $invoice->loadMissing(['user', 'items', 'service.plan']);

            $pdf = Pdf::loadView('pdf.payment-receipt', [
                'invoice'  => $invoice,
                'company'  => $this->companyData(),
                'deadline' => $invoice->paid_at?->addHours(72) ?? now()->addHours(72),
            ])->setPaper('letter', 'portrait');

            $filename = sprintf('%s/%s.pdf', self::DIR, $invoice->uuid);

            Storage::disk(self::DISK)->put($filename, $pdf->output());

            $invoice->updateQuietly(['pdf_path' => $filename]);

            return $filename;
        } catch (\Throwable $e) {
            Log::error('PaymentReceiptService: PDF generation failed', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Return the raw PDF bytes for streaming/download.
     * Re-generates on the fly if the file is missing.
     */
    public function getContent(Receipt $invoice): ?string
    {
        if ($invoice->pdf_path && Storage::disk(self::DISK)->exists($invoice->pdf_path)) {
            return Storage::disk(self::DISK)->get($invoice->pdf_path);
        }

        // File missing — regenerate and try again
        $path = $this->generate($invoice->fresh(['user', 'items', 'service.plan']));

        return $path ? Storage::disk(self::DISK)->get($path) : null;
    }

    private function companyData(): array
    {
        return [
            'name'    => config('app.name', 'ROKE Industries'),
            'address' => config('company.address', ''),
            'rfc'     => config('company.rfc', ''),
            'email'   => config('mail.from.address', ''),
            'phone'   => config('company.phone', ''),
            'website' => config('app.url', ''),
        ];
    }
}
