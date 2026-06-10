<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Models\AppAdmin;
use App\Domains\Pet\Models\PetPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlanController extends Controller
{
    // ── Público ───────────────────────────────────────────────────────────────

    /** Lista los planes activos para la página de pricing */
    public function index(): JsonResponse
    {
        $plans = PetPlan::active()->ordered()->get()->map(fn ($p) => $this->format($p));
        return response()->json($plans);
    }

    /** Detalle de un plan por slug */
    public function show(string $slug): JsonResponse
    {
        $plan = PetPlan::where('slug', $slug)->where('is_active', true)->firstOrFail();
        return response()->json($this->format($plan));
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    /** Lista todos los planes (activos e inactivos) para el panel admin */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $plans = PetPlan::ordered()->get()->map(fn ($p) => $this->format($p, admin: true));
        return response()->json($plans);
    }

    /** Crea un nuevo plan */
    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'name'                 => 'required|string|max:100',
            'slug'                 => 'required|string|max:60|unique:roke_pet.pet_plans,slug|alpha_dash',
            'description'          => 'nullable|string|max:500',
            'price_monthly'        => 'required|numeric|min:0',
            'price_yearly'         => 'nullable|numeric|min:0',
            'trial_enabled'        => 'sometimes|boolean',
            'trial_days'           => 'sometimes|integer|min:0|max:90',
            'max_pets'             => 'nullable|integer|min:1',
            'features'             => 'nullable|array',
            'stripe_price_monthly' => 'nullable|string|max:100',
            'stripe_price_yearly'  => 'nullable|string|max:100',
            'is_active'            => 'sometimes|boolean',
            'sort_order'           => 'sometimes|integer|min:0|max:255',
            'highlighted'          => 'sometimes|boolean',
            'audience'             => 'nullable|string|max:120',
            'badge'                => 'nullable|string|max:80',
            'cta_label'            => 'nullable|string|max:100',
            'checkout_url'         => 'nullable|url|max:500',
            'metadata'             => 'nullable|array',
        ]);

        $plan = PetPlan::create($data);

        return response()->json($this->format($plan, admin: true), 201);
    }

    /** Actualiza un plan existente */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        $plan = PetPlan::findOrFail($id);

        $data = $request->validate([
            'name'                 => 'sometimes|string|max:100',
            'slug'                 => "sometimes|string|max:60|alpha_dash|unique:roke_pet.pet_plans,slug,{$plan->id},id",
            'description'          => 'nullable|string|max:500',
            'price_monthly'        => 'sometimes|numeric|min:0',
            'price_yearly'         => 'nullable|numeric|min:0',
            'trial_enabled'        => 'sometimes|boolean',
            'trial_days'           => 'sometimes|integer|min:0|max:90',
            'max_pets'             => 'nullable|integer|min:1',
            'features'             => 'nullable|array',
            'stripe_price_monthly' => 'nullable|string|max:100',
            'stripe_price_yearly'  => 'nullable|string|max:100',
            'is_active'            => 'sometimes|boolean',
            'sort_order'           => 'sometimes|integer|min:0|max:255',
            'highlighted'          => 'sometimes|boolean',
            'audience'             => 'nullable|string|max:120',
            'badge'                => 'nullable|string|max:80',
            'cta_label'            => 'nullable|string|max:100',
            'checkout_url'         => 'nullable|url|max:500',
            'metadata'             => 'nullable|array',
        ]);

        $plan->update($data);

        return response()->json($this->format($plan->fresh(), admin: true));
    }

    /** Activa o desactiva un plan */
    public function toggle(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        $plan = PetPlan::findOrFail($id);
        $plan->update(['is_active' => !$plan->is_active]);

        return response()->json($this->format($plan->fresh(), admin: true));
    }

    /** Elimina un plan (solo si no tiene suscripciones activas) */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        $plan = PetPlan::findOrFail($id);

        $inUse = \Illuminate\Support\Facades\DB::connection('roke_pet')
            ->table('owner_subscriptions')
            ->where('plan_code', $plan->slug)
            ->whereIn('status', ['active', 'trialing'])
            ->exists();

        if ($inUse) {
            return response()->json([
                'error' => 'No se puede eliminar: hay suscripciones activas en este plan.',
            ], 422);
        }

        $plan->delete();

        return response()->json(['ok' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function format(PetPlan $p, bool $admin = false): array
    {
        $base = [
            'id'           => $p->id,
            'code'         => $p->slug,
            'name'         => $p->name,
            'slug'         => $p->slug,
            'description'  => $p->description,
            'audience'     => $p->audience,
            'badge'        => $p->badge ?: null,
            'highlighted'  => (bool) $p->highlighted,
            'ctaLabel'     => $p->cta_label,
            'checkoutUrl'  => $p->checkout_url ?: null,
            'priceMonthly' => $p->price_monthly,
            'priceYearly'  => $p->price_yearly,
            'trialEnabled' => $p->trial_enabled,
            'trialDays'    => $p->trial_days,
            'maxPets'      => $p->max_pets,
            'features'     => $this->normalizeFeatures($p->features ?? []),
            'isActive'     => $p->is_active,
            'sortOrder'    => $p->sort_order,
        ];

        if ($admin) {
            $base['stripePriceMonthly'] = $p->stripe_price_monthly;
            $base['stripePriceYearly']  = $p->stripe_price_yearly;
            $base['metadata']           = $p->metadata;
            $base['createdAt']          = $p->created_at;
            $base['updatedAt']          = $p->updated_at;
        }

        return $base;
    }

    private function normalizeFeatures(array $features): array
    {
        $result = [];
        foreach ($features as $feature) {
            if (is_string($feature) && trim($feature) !== '') {
                $label = trim($feature);
                $result[] = [
                    'key'      => $this->inferFeatureKey($label),
                    'label'    => $label,
                    'included' => true,
                ];
            } elseif (is_array($feature) && !empty($feature['label'])) {
                $result[] = [
                    'key'         => $feature['key'] ?? $this->inferFeatureKey((string) $feature['label']),
                    'label'       => $feature['label'],
                    'description' => $feature['description'] ?? null,
                    'included'    => $feature['included'] ?? true,
                ];
            }
        }
        return $result;
    }

    private function inferFeatureKey(string $label): ?string
    {
        $slug = Str::slug($label);
        $known = [
            'enlaces-veterinarios-temporales' => 'vet_links',
            'enlaces-veterinarios'            => 'vet_links',
            'links-veterinarios-ilimitados'   => 'vet_links',
            'links-veterinarios'              => 'vet_links',
            'historial-de-peso-con-graficas'  => 'weight_tracking',
            'analitica-de-escaneos'           => 'scan_analytics',
            'analitica-avanzada-de-escaneos'  => 'scan_analytics',
            'historial-de-escaneos'           => 'scan_analytics',
            'recordatorios-push-en-la-app'    => 'push_notifications',
            'recordatorios-push'              => 'push_notifications',
            'recordatorios-push-email'        => 'push_notifications',
            'recordatorios-email-push'        => 'push_notifications',
            'historial-medico-completo'       => 'medical_history_full',
            'cartilla-e-historial-medico'     => 'medical_history_full',
        ];

        if (isset($known[$slug])) {
            return $known[$slug];
        }

        if (Str::contains($slug, ['veterinario', 'vet-link', 'vet-links'])) {
            return 'vet_links';
        }
        if (Str::contains($slug, 'peso')) {
            return 'weight_tracking';
        }
        if (Str::contains($slug, ['escaneo', 'scan', 'analitica'])) {
            return 'scan_analytics';
        }
        if (Str::contains($slug, 'push')) {
            return 'push_notifications';
        }
        if (Str::contains($slug, ['historial-medico', 'cartilla'])) {
            return 'medical_history_full';
        }

        return null;
    }

    private function requireAdmin(Request $request): void
    {
        if (!AppAdmin::where('user_id', $request->user()->uuid)->exists()) {
            abort(403, 'Acceso denegado');
        }
    }
}
