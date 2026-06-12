<?php

namespace App\Domains\Platform\Compute\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $limits = config('compute.limits');

        return [
            // app/static_site → ProvisionAppFlow; database/redis → ProvisionDatabaseFlow.
            // game_server (semana 4) se agrega cuando su flujo exista.
            'kind'         => ['required', Rule::in(['app', 'static_site', 'database', 'redis'])],
            'name'         => ['required', 'string', 'max:100'],
            // Engine obligatorio para kind=database; redis lo implica el propio kind.
            'spec'         => ['sometimes', 'array'],
            'spec.engine'  => [
                Rule::requiredIf(fn () => $this->input('kind') === 'database'),
                'in:mysql,postgres',
            ],
            'spec.ram_mb'  => [
                'sometimes', 'integer',
                'min:' . $limits['ram_mb_min'],
                'max:' . $limits['ram_mb_max'],
            ],
            'spec.cpu'     => ['sometimes', 'numeric', 'min:0.25', 'max:4'],
        ];
    }
}
