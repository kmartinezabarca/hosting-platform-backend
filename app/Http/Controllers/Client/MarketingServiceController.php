<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;

use App\Models\MarketingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MarketingServiceController extends Controller
{
    /**
     * Display a listing of the marketing services.
     */
    public function index(): JsonResponse
    {
        try {
            // Recuperar todos los servicios de marketing, ordenados por el campo 'order'
            $services = MarketingService::orderBy('order')->get();

            // Devolver los servicios como una respuesta JSON
            return response()->json([
                "success" => true,
                "data" => $services
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching service plans: " . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error fetching service plans"
            ], 500);
        }
    }
}
