<?php

namespace App\Domains\Platform\Compute\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    /** La autorización fina (membresía + rol Developer+) la hace ProjectPolicy::update. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Ajustes editables del proyecto. Solo nombre y rama de producción: cambiar
     * el repo conectado es un flujo aparte (re-detección + revinculación) y no
     * se hace desde Ajustes.
     */
    public function rules(): array
    {
        return [
            'name'           => ['sometimes', 'required', 'string', 'max:100'],
            'default_branch' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
