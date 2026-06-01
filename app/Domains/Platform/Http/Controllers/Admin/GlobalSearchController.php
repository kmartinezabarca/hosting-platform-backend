<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Platform\Models\Invoice;
use App\Domains\Platform\Models\Service;
use App\Domains\Platform\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GlobalSearchController extends Controller
{
    private const LIMIT        = 5;
    private const CACHE_TTL    = 300;       // 5 min result cache
    private const POPULAR_TTL  = 86400 * 7; // 7 days for popular tracking
    private const POPULAR_KEY  = 'admin:search:popular';
    private const MAX_POPULAR  = 8;

    public function search(Request $request): JsonResponse
    {
        $q = mb_substr(trim($request->get('q', '')), 0, 100);

        if (strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $cacheKey = 'admin:search:' . md5(strtolower($q));

        $results = Cache::remember($cacheKey, self::CACHE_TTL, fn() => array_merge(
            $this->searchUsers($q),
            $this->searchServices($q),
            $this->searchInvoices($q),
            $this->searchTickets($q),
        ));

        $this->trackPopular($q);

        return response()->json(['success' => true, 'data' => $results]);
    }

    public function popular(): JsonResponse
    {
        $counts = Cache::get(self::POPULAR_KEY, []);
        arsort($counts);
        $top = array_slice(array_keys($counts), 0, self::MAX_POPULAR);

        return response()->json(['success' => true, 'data' => $top]);
    }

    private function trackPopular(string $q): void
    {
        $normalized = mb_strtolower(trim($q));
        if (strlen($normalized) < 2) return;

        $counts = Cache::get(self::POPULAR_KEY, []);
        $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
        Cache::put(self::POPULAR_KEY, $counts, self::POPULAR_TTL);
    }

    private function searchUsers(string $q): array
    {
        return User::where(fn($query) =>
            $query->where('first_name', 'like', "%{$q}%")
                  ->orWhere('last_name',  'like', "%{$q}%")
                  ->orWhere('email',      'like', "%{$q}%")
                  ->orWhere('phone',      'like', "%{$q}%")
        )
        ->select('id', 'uuid', 'first_name', 'last_name', 'email')
        ->limit(self::LIMIT)
        ->get()
        ->map(fn($u) => [
            'id'       => $u->id,
            'uuid'     => $u->uuid,
            'name'     => trim("{$u->first_name} {$u->last_name}") ?: $u->email,
            'email'    => $u->email,
            'href'     => "/admin/users/{$u->id}",
            'category' => 'Usuarios',
            'icon'     => 'Users',
            'type'     => 'user',
        ])
        ->all();
    }

    private function searchServices(string $q): array
    {
        return Service::where(fn($query) =>
            $query->where('name',   'like', "%{$q}%")
                  ->orWhere('domain', 'like', "%{$q}%")
        )
        ->select('id', 'uuid', 'name', 'domain', 'status')
        ->limit(self::LIMIT)
        ->get()
        ->map(fn($s) => [
            'id'          => $s->id,
            'uuid'        => $s->uuid,
            'name'        => $s->name ?: "Servicio #{$s->id}",
            'description' => $s->status,
            'href'        => "/admin/services/{$s->id}",
            'category'    => 'Servicios',
            'icon'        => 'Server',
            'type'        => 'service',
        ])
        ->all();
    }

    private function searchInvoices(string $q): array
    {
        return Invoice::where(fn($query) =>
            $query->where('invoice_number', 'like', "%{$q}%")
        )
        ->select('id', 'uuid', 'invoice_number', 'status')
        ->limit(self::LIMIT)
        ->get()
        ->map(fn($inv) => [
            'id'          => $inv->id,
            'uuid'        => $inv->uuid,
            'name'        => "Factura #{$inv->invoice_number}",
            'description' => $inv->status,
            'href'        => "/admin/invoices/{$inv->id}",
            'category'    => 'Facturas',
            'icon'        => 'CreditCard',
            'type'        => 'invoice',
        ])
        ->all();
    }

    private function searchTickets(string $q): array
    {
        return Ticket::where(fn($query) =>
            $query->where('subject',       'like', "%{$q}%")
                  ->orWhere('ticket_number', 'like', "%{$q}%")
        )
        ->select('id', 'uuid', 'subject', 'ticket_number', 'status')
        ->limit(self::LIMIT)
        ->get()
        ->map(fn($t) => [
            'id'          => $t->id,
            'uuid'        => $t->uuid,
            'name'        => $t->subject ?: "Ticket #{$t->id}",
            'description' => $t->status,
            'href'        => "/admin/tickets/{$t->id}",
            'category'    => 'Tickets',
            'icon'        => 'Ticket',
            'type'        => 'ticket',
        ])
        ->all();
    }
}
