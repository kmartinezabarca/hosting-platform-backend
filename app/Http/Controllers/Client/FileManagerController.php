<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Pterodactyl\PterodactylService;
use App\Exceptions\PterodactylApiException;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FileManagerController extends Controller
{
    public function __construct(
        private readonly PterodactylService $pterodactyl
    ) {}

    /**
     * GET /services/{uuid}/files/list
     */
    public function listFiles(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['directory' => ['required', 'string']]);
        $service   = $this->findOwnedService($request, $uuid);

        try {
            $files = $this->pterodactyl->listFiles(
                $this->identifier($service),
                $validated['directory']
            );

            return response()->json(['data' => $files]);

        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        }
    }

    /**
     * GET /services/{uuid}/files/upload
     * Retorna la URL firmada para subir un archivo.
     */
    public function getUploadUrl(Request $request, string $uuid): JsonResponse
    {
        $service = $this->findOwnedService($request, $uuid);

        try {
            $url = $this->pterodactyl->getUploadUrl($this->identifier($service));
            return response()->json(['data' => ['url' => $url]]);

        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        }
    }

    /**
     * POST /services/{uuid}/files/delete
     */
    public function deleteFiles(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'root'    => ['required', 'string'],
            'files'   => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'string'],
        ]);

        $service = $this->findOwnedService($request, $uuid);

        try {
            $this->pterodactyl->deleteFiles(
                $this->identifier($service),
                $validated['root'],
                $validated['files']
            );

            return response()->json(['message' => 'ok']);

        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        }
    }

    /**
     * GET /services/{uuid}/files/download
     * Retorna la URL firmada para descargar un archivo.
     */
    public function getDownloadUrl(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['file' => ['required', 'string']]);
        $service   = $this->findOwnedService($request, $uuid);

        try {
            $url = $this->pterodactyl->getDownloadUrl(
                $this->identifier($service),
                $validated['file']
            );

            return response()->json(['data' => ['url' => $url]]);

        } catch (PterodactylApiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->statusCode());
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function findOwnedService(Request $request, string $uuid): Service
    {
        $service = Service::where('user_id', $request->user()->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        if (!$service->isPterodactylManaged()) {
            abort(response()->json(['message' => 'Este servicio no es un servidor de juego administrado.'], 422));
        }

        return $service;
    }

    private function identifier(Service $service): string
    {
        $identifier = $service->connection_details['identifier'] ?? null;

        if (!$identifier) {
            abort(response()->json(['message' => 'El servidor no tiene un identificador asignado.'], 404));
        }

        return $identifier;
    }
}
