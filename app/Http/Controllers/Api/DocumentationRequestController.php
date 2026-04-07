<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DocumentationRequestController extends Controller
{
    /**
     * Store a newly created documentation request in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'topic' => 'required|string|max:255',
            'description' => 'nullable|string',
            'kind' => 'required|in:documentation,api_documentation',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $documentationRequest = DocumentationRequest::create($request->all());

        return response()->json(['message' => 'Solicitud de documentación enviada exitosamente.', 'data' => $documentationRequest], 201);
    }
}
