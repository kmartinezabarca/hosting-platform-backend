<?php

namespace App\Domains\Platform\SiteBuilder\Http\Controllers;

use App\Domains\Platform\SiteBuilder\Models\GeneratedPage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Sirve públicamente el HTML de una página generada (SiteBuilder fase 2, op. A).
 *
 * Público y sin estado. Solo entrega páginas PUBLICADAS (published_at no nulo);
 * el resto responde 404. Se debe exponer en un dominio separado y sin cookies
 * (rokeindustries.app), nunca en el del api/app: el HTML es contenido de usuario
 * y no debe compartir origen con la cookie de sesión.
 */
class PublicPageController extends Controller
{
    /**
     * GET /p/{uuid}
     */
    public function serve(string $uuid): Response
    {
        $page = GeneratedPage::where('uuid', $uuid)->first();

        abort_if($page === null || ! $page->isPublished(), 404);

        return response($page->html, 200, [
            'Content-Type'           => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
