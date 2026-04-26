<?php

namespace Tests\Unit;

use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_resource_transforms_basic_fields(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'paid',
        ]);

        $resource = new InvoiceResource($invoice);
        $data = $resource->toArray(request());

        $this->assertEquals($invoice->uuid, $data['uuid']);
        $this->assertEquals($invoice->invoice_number, $data['invoice_number']);
        $this->assertEquals($invoice->status, $data['status']);
        $this->assertEquals((float) $invoice->subtotal, $data['subtotal']);
        $this->assertEquals((float) $invoice->tax_rate, $data['tax_rate']);
        $this->assertEquals((float) $invoice->total, $data['total']);
    }

    public function test_invoice_resource_includes_items_when_loaded(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
        ]);

        $invoice->items()->create([
            'description' => 'Test Service',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ]);

        $invoice->load('items');

        $resource = new InvoiceResource($invoice);
        $data = $resource->toArray(request());

        $this->assertArrayHasKey('items', $data);
        $this->assertCount(1, $data['items']);
    }

    public function test_invoice_resource_formats_dates_correctly(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'due_date' => now()->addDays(30),
        ]);

        $resource = new InvoiceResource($invoice);
        $data = $resource->toArray(request());

        $this->assertNotNull($data['due_date']);
        $this->assertStringContainsString('-', $data['due_date']);
    }

    public function test_invoice_resource_formats_paid_at_datetime(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $resource = new InvoiceResource($invoice);
        $data = $resource->toArray(request());

        $this->assertNotNull($data['paid_at']);
        $this->assertStringContainsString('T', $data['paid_at']);
    }
}
