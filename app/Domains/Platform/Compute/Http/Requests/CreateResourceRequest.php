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
            // Semana 3: solo apps. database/redis (mes 2) y game_server
            // (semana 4) se agregan cuando sus flujos existan.
            'kind'        => ['required', Rule::in(['app', 'static_site'])],
            'name'        => ['required', 'string', 'max:100'],
            'spec'        => ['sometimes', 'array'],
            'spec.ram_mb' => [
                'sometimes', 'integer',
                'min:' . $limits['ram_mb_min'],
                'max:' . $limits['ram_mb_max'],
            ],
            'spec.cpu'    => ['sometimes', 'numeric', 'min:0.25', 'max:4'],
        ];
    }
}
