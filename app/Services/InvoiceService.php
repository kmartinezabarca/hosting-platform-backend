<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    // ──────────────────────────────────────────────
    // Invoice Number Generation
    // ──────────────────────────────────────────────

    /**
     * Generate the next sequential invoice number for the current month.
     * Format: INV-YYYYMM0001
     */
    public function generateNumber(): string
    {
        $prefix = config('app.receipt_prefix', 'REC-');
        $year   = now()->format('Y');
        $month  = now()->format('m');

        $last = Receipt::where('invoice_number', 'like', $prefix . $year . $month . '%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . $year . $month . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    // ──────────────────────────────────────────────
    // Invoice Creation
    // ──────────────────────────────────────────────

    /**
     * Create an invoice with its line items inside a transaction.
     *
     * $data keys: user_id, service_id (optional), status, due_date, currency,
     *             subtotal, tax_rate, tax_amount, total, notes (optional),
     *             payment_method (optional), payment_reference (optional)
     *
     * $items[] keys: description, quantity, unit_price, service_id (optional)
     */
    public function createWithItems(array $data, array $items): Receipt
    {
        return DB::transaction(function () use ($data, $items) {
            $receipt = Receipt::create(array_merge($data, [
                'invoice_number' => $this->generateNumber(),
            ]));

            foreach ($items as $item) {
                InvoiceItem::create([
                    'invoice_id'  => $receipt->id,
                    'service_id'  => $item['service_id'] ?? null,
                    'description' => $item['description'],
                    'quantity'    => (int) $item['quantity'],
                    'unit_price'  => (float) $item['unit_price'],
                    'total'       => round((float) $item['quantity'] * (float) $item['unit_price'], 2),
                ]);
            }

            return $receipt->load('items');
        });
    }

    // ──────────────────────────────────────────────
    // Statistics
    // ──────────────────────────────────────────────

    /**
     * Aggregated invoice stats for a given user.
     */
    public function getStatsForUser(int $userId): array
    {
        $base = fn() => Receipt::where('user_id', $userId);
        $paidBase = fn() => $base()->where('status', 'paid');

        // Total realmente FACTURADO = recibos del usuario que ya tienen
        // su CFDI timbrado (factura fiscal emitida).
        $invoicedAmount = $base()
            ->whereHas('invoice', fn ($q) => $q->where('cfdi_status', 'stamped'))
            ->sum('total');

        $now = now();
        $totalPaidYear = $paidBase()
            ->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$now->copy()->startOfYear(), $now->copy()->endOfYear()])
            ->sum('total');

        $totalPaidMonth = $paidBase()
            ->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->sum('total');

        $stampedCount = \App\Models\Invoice::whereHas('receipt', fn ($q) => $q->where('user_id', $userId))
            ->where('cfdi_status', 'stamped')
            ->count();

        return [
            'total_invoices'   => $base()->count(),
            'paid_invoices'    => $base()->where('status', 'paid')->count(),
            'pending_invoices' => $base()->whereIn('status', ['draft', 'sent'])->count(),
            'overdue_invoices' => $base()->where('status', 'overdue')->count(),
            // Montos de RECIBOS (pagos)
            'total_amount'     => $base()->sum('total'),
            'paid_amount'      => $base()->where('status', 'paid')->sum('total'),
            'pending_amount'   => $base()->whereIn('status', ['draft', 'sent', 'overdue'])->sum('total'),
            // Alias claros + métrica fiscal real
            'total_paid'       => $base()->where('status', 'paid')->sum('total'),
            'total_paid_year'  => $totalPaidYear,
            'total_paid_month' => $totalPaidMonth,
            'total_invoiced'   => $invoicedAmount,   // solo CFDI timbrados
            'stamped_count'    => $stampedCount,
        ];
    }
}
