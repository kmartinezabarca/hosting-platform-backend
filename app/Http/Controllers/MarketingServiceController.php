<?php

namespace App\Http\Controllers;

use App\Models\MarketingService;
use Illuminate\Http\Request;

class MarketingServiceController extends Controller
{
    /**
     * Display a listing of the marketing services.
     */
    public function index()
    {
        // Recuperar todos los servicios de marketing, ordenados por el campo 'order'
        $services = MarketingService::orderBy(\'order\')->get();

        // Devolver los servicios como una respuesta JSON
        return response()->json($services);
    }
}

