<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function show(string $path): StreamedResponse|Response
    {
        $path = ltrim($path, '/');

        if (str_contains($path, '..') || ! $this->isAllowedPath($path)) {
            abort(404);
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function isAllowedPath(string $path): bool
    {
        return str_starts_with($path, 'pet-photos/')
            || str_starts_with($path, 'pet-covers/');
    }
}
