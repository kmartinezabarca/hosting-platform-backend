<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserRequestController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'topic' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'kind' => 'required|in:blog_subscription,documentation_request,api_documentation_request',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $userRequest = UserRequest::create($request->all());

        return response()->json(['message' => 'Solicitud enviada con éxito', 'data' => $userRequest], 201);
    }
}
