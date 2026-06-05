<?php

namespace App\Domains\Platform\Http\Controllers\Api;

use App\Domains\Platform\Models\ContactRequest as ContactRequestModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ContactRequest;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    public function store(ContactRequest $request): JsonResponse
    {
        $data = $request->safe()->except('cf-turnstile-response');

        $contactRequest = ContactRequestModel::create([
            ...$data,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud de contacto enviada exitosamente.',
            'data' => [
                'uuid' => $contactRequest->uuid,
            ],
        ], 201);
    }
}
