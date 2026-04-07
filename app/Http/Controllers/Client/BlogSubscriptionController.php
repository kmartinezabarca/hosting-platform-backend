<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\UserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BlogSubscriptionController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255|unique:user_requests,email,NULL,id,kind,blog_subscription',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $userRequest = UserRequest::create([
            'name' => $request->input('name', 'Anónimo'), // Asume un nombre si no se proporciona
            'email' => $request->input('email'),
            'kind' => 'blog_subscription',
            'topic' => 'Suscripción al Blog',
            'description' => 'Solicitud de suscripción al blog.',
            'status' => 'pending',
            'is_resolved' => false,
        ]);

        return response()->json(['message' => 'Suscripción al blog realizada con éxito.', 'data' => $userRequest], 201);
    }

    public function unsubscribe(string $uuid): JsonResponse
    {
        $userRequest = UserRequest::where('id', $uuid)
                                  ->where('kind', 'blog_subscription')
                                  ->firstOrFail();
        
        $userRequest->update(['is_resolved' => true, 'status' => 'unsubscribed']);

        return response()->json(['message' => 'Suscripción cancelada exitosamente.']);
    }
}
