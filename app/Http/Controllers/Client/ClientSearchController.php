<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Buscador global del portal de cliente.
 *
 * Busca en los recursos propios del usuario autenticado:
 *  - Servicios    (nombre, dominio)
 *  - Comprobantes (número de comprobante)
 *  - Tickets      (asunto, número de ticket)
 *
 * Un solo endpoint reemplaza múltiples llamadas paralelas.
 */
class ClientSearchController extends Controller
{
    private const LIMIT       = 5;
    private const CACHE_TTL   = 120;       // 2 min — resultados cambian con frecuencia para el cliente
    private const POPULAR_TTL = 86400 * 3; // 3 días de popularidad por usuario
    private const MAX_POPULAR = 6;

    public function search(Request $request): JsonResponse
    {
        $q    = trim($request->get('q', ''));
        $user = Auth::user();

        if (strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // Cache por usuario + query para aislar resultados entre usuarios
        $cacheKey = "client:search:{$user->id}:" . md5(strtolower($q));

        $results = Cache::remember($cacheKey, self::CACHE_TTL, fn() => array_merge(
            $this->searchServices($q, $user->id),
            $this->searchReceipts($q, $user->id),
            $this->searchTickets($q, $user->id),
        ));

        $this->trackPopular($q, $user->uuid);

        return response()->json(['success' => true, 'data' => $results]);
    }

    public function popular(Request $request): JsonResponse
    {
        $user   = Auth::user();
        $counts = Cache::get("client:search:popular:{$user->uuid}", []);
        arsort($counts);
        $top = array_slice(array_keys($counts), 0, self::MAX_POPULAR);

        return response()->json(['success' => true, 'data' => $top]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function trackPopular(string $q, string $userUuid): void
    {
        $normalized = mb_strtolower(trim($q));
        if (strlen($normalized) < 2) return;

        $key    = "client:search:popular:{$userUuid}";
        $counts = Cache::get($key, []);
        $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
        Cache::put($key, $counts, self::POPULAR_TTL);
    }

    private function searchServices(string $q, int $userId): array
    {
        return Service::where('user_id', $userId)
            ->where(fn($query) =>
                $query->where('name',   'like', "%{$q}%")
                      ->orWhere('domain', 'like', "%{$q}%")
            )
            ->select('id', 'uuid', 'name', 'domain', 'status')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn($s) => [
                'id'          => $s->id,
                'uuid'        => $s->uuid,
                'name'        => $s->name ?: ($s->domain ?: "Servicio #{$s->id}"),
                'description' => implode(' · ', array_filter([$s->domain, $s->status])),
                'href'        => "/client/services/{$s->uuid}",
                'category'    => 'Servicios',
                'icon'        => 'Server',
                'type'        => 'service',
            ])
            ->all();
    }

    private function searchReceipts(string $q, int $userId): array
    {
        return Receipt::where('user_id', $userId)
            ->where('invoice_number', 'like', "%{$q}%")
            ->select('id', 'uuid', 'invoice_number', 'status', 'total')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn($r) => [
                'id'          => $r->id,
                'uuid'        => $r->uuid,
                'name'        => "Comprobante {$r->invoice_number}",
                'description' => ucfirst($r->status) . ($r->total ? " · \${$r->total}" : ''),
                'href'        => '/client/invoices',
                'category'    => 'Comprobantes',
                'icon'        => 'Receipt',
                'type'        => 'invoice',
            ])
            ->all();
    }

    private function searchTickets(string $q, int $userId): array
    {
        return Ticket::where('user_id', $userId)
            ->where(fn($query) =>
                $query->where('subject',       'like', "%{$q}%")
                      ->orWhere('ticket_number', 'like', "%{$q}%")
            )
            ->select('id', 'uuid', 'subject', 'ticket_number', 'status', 'priority')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn($t) => [
                'id'          => $t->id,
                'uuid'        => $t->uuid,
                'name'        => $t->subject ?: "Ticket #{$t->ticket_number}",
                'description' => ucfirst($t->status) . ($t->priority ? " · {$t->priority}" : ''),
                'href'        => '/client/tickets',
                'category'    => 'Soporte',
                'icon'        => 'MessageSquare',
                'type'        => 'ticket',
            ])
            ->all();
    }
}
