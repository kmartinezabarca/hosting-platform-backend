<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Models\Pet;
use App\Domains\Pet\Models\PetPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Platform-admin search across ROKE Pet entities.
 * Auth: platform admin (auth:sanctum + admin middleware) — NOT pet AppAdmin.
 */
class PetSearchController extends Controller
{
    private const POPULAR_KEY = 'pet:search:popular';
    private const POPULAR_TTL = 86400 * 7; // 7 days
    private const MAX_POPULAR = 8;
    private const LIMIT       = 5;

    /**
     * GET /admin/pet/search/popular
     *
     * Returns the most searched terms within the pet admin panel (last 7 days).
     */
    public function popular(): JsonResponse
    {
        $counts = Cache::get(self::POPULAR_KEY, []);
        arsort($counts);
        $top = array_slice(array_keys($counts), 0, self::MAX_POPULAR);

        return response()->json(['success' => true, 'data' => $top]);
    }

    /**
     * GET /admin/pet/search?q=...
     *
     * Full-text search across owners, pets, and plans.
     */
    public function search(Request $request): JsonResponse
    {
        $q = mb_substr(trim($request->get('q', '')), 0, 100);

        if (strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $results = array_merge(
            $this->searchOwners($q),
            $this->searchPets($q),
            $this->searchPlans($q),
        );

        $this->trackPopular($q);

        return response()->json(['success' => true, 'data' => $results]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function searchOwners(string $q): array
    {
        return Owner::query()
            ->where(function ($query) use ($q) {
                $query->where('display_name', 'like', "%{$q}%")
                      ->orWhere('email',        'like', "%{$q}%")
                      ->orWhere('id',            'like', "%{$q}%");
            })
            ->limit(self::LIMIT)
            ->get(['id', 'display_name', 'email'])
            ->map(fn ($o) => [
                'type'     => 'owner',
                'id'       => $o->id,
                'label'    => $o->display_name ?? $o->email,
                'sublabel' => $o->email,
            ])
            ->toArray();
    }

    private function searchPets(string $q): array
    {
        return Pet::query()
            ->where(function ($query) use ($q) {
                $query->where('name',    'like', "%{$q}%")
                      ->orWhere('breed', 'like', "%{$q}%")
                      ->orWhere('id',    'like', "%{$q}%");
            })
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'breed', 'owner_id'])
            ->map(fn ($p) => [
                'type'     => 'pet',
                'id'       => $p->id,
                'label'    => $p->name,
                'sublabel' => $p->breed ?? 'Sin raza',
            ])
            ->toArray();
    }

    private function searchPlans(string $q): array
    {
        return PetPlan::query()
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('slug', 'like', "%{$q}%");
            })
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'slug'])
            ->map(fn ($pl) => [
                'type'     => 'plan',
                'id'       => $pl->id,
                'label'    => $pl->name,
                'sublabel' => $pl->slug,
            ])
            ->toArray();
    }

    private function trackPopular(string $q): void
    {
        $term   = strtolower($q);
        $counts = Cache::get(self::POPULAR_KEY, []);
        $counts[$term] = ($counts[$term] ?? 0) + 1;

        // Keep only top terms to avoid unbounded growth
        arsort($counts);
        $counts = array_slice($counts, 0, 50, true);

        Cache::put(self::POPULAR_KEY, $counts, self::POPULAR_TTL);
    }
}
