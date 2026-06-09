<?php

namespace Database\Factories;

use App\Domains\Platform\Models\Invoice;
use App\Domains\Platform\Models\Receipt;
use App\Domains\Platform\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domains\Platform\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'uuid'                => (string) Str::uuid(),
            'receipt_id'          => Receipt::factory(),
            'service_id'          => Service::factory(),
            'rfc'                 => Invoice::PUBLICO_GENERAL_RFC,
            'name'                => Invoice::PUBLICO_GENERAL_NAME,
            'zip'                 => Invoice::PUBLICO_GENERAL_ZIP,
            'regimen'             => Invoice::PUBLICO_GENERAL_REGIMEN,
            'cfdi_use_code'       => Invoice::PUBLICO_GENERAL_USO,
            'cfdi_status'         => Invoice::CFDI_SCHEDULED,
            'stamp_scheduled_at'  => now()->addHours(72),
            'is_publico_general'  => true,
            'folio'               => fake()->unique()->numberBetween(1, 999999),
        ];
    }

    public function pendingStamp(): static
    {
        return $this->state(fn () => [
            'cfdi_status'        => Invoice::CFDI_PENDING_STAMP,
            'stamp_scheduled_at' => null,
            'is_publico_general' => false,
        ]);
    }

    public function stamped(): static
    {
        return $this->state(fn () => [
            'cfdi_status' => Invoice::CFDI_STAMPED,
            'cfdi_uuid'   => (string) Str::uuid(),
            'stamped_at'  => now(),
            'cfdi_error'  => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'cfdi_status' => Invoice::CFDI_FAILED,
            'cfdi_error'  => 'No se pudo timbrar el CFDI.',
        ]);
    }
}
