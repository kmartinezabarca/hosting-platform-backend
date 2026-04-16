<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoiceService();
    }

    // ──────────────────────────────────────────────
    // generateNumber()
    // ──────────────────────────────────────────────

    public function test_generates_first_number_when_no_invoices_exist(): void
    {
        $prefix   = config('app.invoice_prefix', 'INV-');
        $expected = $prefix . now()->format('Y') . now()->format('m') . '0001';

        $this->assertSame($expected, $this->service->generateNumber());
    }

    public function test_increments_sequence_from_existing_invoices(): void
    {
        $prefix = config('app.invoice_prefix', 'INV-');
        $ym     = now()->format('Ym');

        // Seed three invoices for this month
        Invoice::factory()->count(3)->sequence(
            ['invoice_number' => "{$prefix}{$ym}0001"],
            ['invoice_number' => "{$prefix}{$ym}0002"],
            ['invoice_number' => "{$prefix}{$ym}0003"],
        )->create();

        $expected = "{$prefix}{$ym}0004";

        $this->assertSame($expected, $this->service->generateNumber());
    }

    public function test_does_not_cross_contaminate_between_months(): void
    {
        $prefix     = config('app.invoice_prefix', 'INV-');
        $currentYm  = now()->format('Ym');
        $previousYm = now()->subMonth()->format('Ym');

        // Simulate invoices from the previous month only
        Invoice::factory()->create(['invoice_number' => "{$prefix}{$previousYm}0099"]);

        // Current month should start fresh at 0001
        $expected = "{$prefix}{$currentYm}0001";

        $this->assertSame($expected, $this->service->generateNumber());
    }

    public function test_number_uses_configured_prefix(): void
    {
        config(['app.invoice_prefix' => 'FACT-']);

        $number = $this->service->generateNumber();

        $this->assertStringStartsWith('FACT-', $number);
    }

    public function test_sequence_is_padded_to_four_digits(): void
    {
        $prefix = config('app.invoice_prefix', 'INV-');
        $ym     = now()->format('Ym');

        Invoice::factory()->create(['invoice_number' => "{$prefix}{$ym}0009"]);

        $next = $this->service->generateNumber();

        $this->assertStringEndsWith('0010', $next);
    }

    public function test_sequence_handles_high_numbers(): void
    {
        $prefix = config('app.invoice_prefix', 'INV-');
        $ym     = now()->format('Ym');

        Invoice::factory()->create(['invoice_number' => "{$prefix}{$ym}9999"]);

        // Should NOT pad — just append the number directly
        $next = $this->service->generateNumber();

        $this->assertStringEndsWith('10000', $next);
    }
}
