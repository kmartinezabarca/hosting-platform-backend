<?php

namespace App\Domains\Platform\Compute\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    /** La autorización fina (membresía + rol) la hace ProjectPolicy::create. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'team'                => ['required', 'uuid', 'exists:teams,uuid'],
            'name'                => ['required', 'string', 'max:100'],
            'repo_full_name'      => ['nullable', 'string', 'max:255', 'regex:#^[\w.-]+/[\w.-]+$#'],
            'default_branch'      => ['nullable', 'string', 'max:255'],
            // ID interno de github_installations; el controller valida que
            // pertenezca al mismo equipo del proyecto.
            'github_installation' => ['nullable', 'integer', 'exists:github_installations,id'],
        ];
    }
}
