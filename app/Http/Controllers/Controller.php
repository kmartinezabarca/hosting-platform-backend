<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Return a standardized error response.
     * Only exposes the exception message in debug mode.
     */
    protected function errorResponse(string $message, \Throwable $e, int $status = 500): \Illuminate\Http\JsonResponse
    {
        Log::error($message, [
            'exception' => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);

        $response = [
            'success' => false,
            'message' => $message,
        ];

        if (config('app.debug')) {
            $response['debug'] = $e->getMessage();
        }

        return response()->json($response, $status);
    }
}
