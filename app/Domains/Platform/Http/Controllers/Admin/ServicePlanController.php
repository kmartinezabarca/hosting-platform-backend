<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Domains\Platform\Models\AuditLog;
use App\Domains\Platform\Models\Category;
use App\Domains\Platform\Models\ServicePlan;
use App\Domains\Platform\Models\PlanFeature;
use App\Domains\Platform\Models\PlanPricing;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ServicePlanController extends Controller
{
    /**
     * List service plans for the admin table (lightweight — no heavy relations)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ServicePlan::with(['category:id,uuid,name,slug'])
                ->select([
                    'id', 'uuid', 'name', 'slug',
                    'category_id', 'base_price', 'setup_fee',
                    'provisioner', 'provisioner_config',
                    'pterodactyl_environment',
                    'is_active', 'is_popular', 'sort_order',
                    DB::raw('(SELECT COUNT(*) FROM plan_features WHERE plan_features.service_plan_id = service_plans.id) as features_count'),
                ]);

            if ($request->filled('category_id')) {
                $query->where('category_id', $request->category_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")
                                          ->orWhere('slug', 'like', "%{$search}%"));
            }

            $perPage = min((int) $request->get('per_page', 15), 100);
            $plans   = $query->orderBy('sort_order')->orderBy('name')->paginate($perPage);
            $plans->getCollection()->transform(fn (ServicePlan $plan) => $this->serializePlan($plan));

            return response()->json([
                'success' => true,
                'data'    => $plans->items(),
                'pagination' => [
                    'current_page'   => $plans->currentPage(),
                    'per_page'       => $plans->perPage(),
                    'total'          => $plans->total(),
                    'last_page'      => $plans->lastPage(),
                    'from'           => $plans->firstItem(),
                    'to'             => $plans->lastItem(),
                    'has_more_pages' => $plans->hasMorePages(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving service plans',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function listAddOns(string $AddSlug)
    {
        $plan = ServicePlan::where('slug', $AddSlug)->firstOrFail();

        $addOns = $plan->addOns()
        ->where('is_active', true)
        ->orderBy('name')
        ->get(['add_ons.uuid', 'add_ons.slug', 'add_ons.name', 'add_ons.description', 'add_ons.price', 'add_ons.currency', 'add_on_plan.is_default']);

        return response()->json(['success' => true, 'data' => $addOns]);
    }

    /**
     * Get service plans by category slug
     */
    public function getByCategorySlug(string $categorySlug): JsonResponse
    {
        try {
            $servicePlans = ServicePlan::active()
                                     ->whereHas('category', function ($query) use ($categorySlug) {
                                         $query->where('slug', $categorySlug);
                                     })
                                     ->with(['category', 'features', 'pricing.billingCycle'])
                                     ->orderBy('sort_order')
                                     ->orderBy('name')
                                     ->get();

            return response()->json([
                'success' => true,
                'data' => $servicePlans
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving service plans',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get a specific service plan by UUID
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $servicePlan = ServicePlan::where('uuid', $uuid)
                                    ->with(['category', 'features', 'pricing.billingCycle'])
                                    ->first();

            if (!$servicePlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service plan not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->serializePlan($servicePlan),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving service plan',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Create a new service plan (Admin only)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $payload = $this->normalizeProvisionerPayload($request->all());

            $validator = Validator::make($payload, [
                'category_id' => 'required|exists:categories,id',
                'slug' => 'required|string|max:100|unique:service_plans',
                'name' => 'required|string|max:200',
                'description' => 'nullable|string',
                'base_price' => 'required|numeric|min:0',
                'setup_fee' => 'nullable|numeric|min:0',
                'is_popular' => 'boolean',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer',
                'specifications' => 'nullable|array',
                'provisioner' => 'nullable|in:pterodactyl,coolify,hestia,manual',
                'provisioner_config' => 'nullable|array',
                'provisioner_config.egg' => 'nullable|string|max:255',
                'provisioner_config.version' => 'nullable|string|max:100',
                'provisioner_config.environment' => 'nullable|array',
                'provisioner_config.build_pack' => 'nullable|string|in:static,php',
                'provisioner_config.db_enabled' => 'nullable|boolean',
                'provisioner_config.db_type' => 'nullable|string|in:mariadb,mysql,postgresql',
                'pterodactyl_egg' => 'nullable|string|max:255',
                'pterodactyl_version' => 'nullable|string|max:100',
                'game_type' => 'nullable|string|max:50',
                'game_runtime_options' => 'nullable|array',
                'game_config_schema' => 'nullable|array',
                'pterodactyl_nest_id' => 'nullable|integer|min:1',
                'pterodactyl_egg_id' => 'nullable|integer|min:1',
                'pterodactyl_node_id' => 'nullable|integer|min:1',
                'pterodactyl_limits' => 'nullable|array',
                'pterodactyl_feature_limits' => 'nullable|array',
                'pterodactyl_environment' => 'nullable|array',
                'pterodactyl_docker_image' => 'nullable|string|max:255',
                'pterodactyl_startup' => 'nullable|string|max:2000',
                // Claves SAT para CFDI
                'sat_clave_prod_serv' => 'nullable|string|max:10',
                'sat_clave_unidad'    => 'nullable|string|max:3',
                // Tipo de plan y trial
                'plan_type'           => 'nullable|in:paid,free,trial',
                'trial_days'          => 'nullable|integer|min:1|max:365',
                'converts_to_plan_id' => 'nullable|integer|exists:service_plans,id',
                'features' => 'nullable|array',
                'features.*' => 'string|max:500',
                'pricing' => 'nullable|array',
                'pricing.*.billing_cycle_id' => 'required|exists:billing_cycles,id',
                'pricing.*.price' => 'required|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Create service plan
            $servicePlan = ServicePlan::create($this->onlyPlanFields($payload));

            // Create features if provided
            if (array_key_exists('features', $payload) && is_array($payload['features'])) {
                foreach ($payload['features'] as $index => $feature) {
                    PlanFeature::create([
                        'service_plan_id' => $servicePlan->id,
                        'feature' => $feature,
                        'sort_order' => $index
                    ]);
                }
            }

            // Create pricing if provided
            if (array_key_exists('pricing', $payload) && is_array($payload['pricing'])) {
                foreach ($payload['pricing'] as $pricing) {
                    PlanPricing::create([
                        'service_plan_id' => $servicePlan->id,
                        'billing_cycle_id' => $pricing['billing_cycle_id'],
                        'price' => $pricing['price']
                    ]);
                }
            }

            DB::commit();

            $this->clearCatalogCaches();

            $servicePlan->load(['category', 'features', 'pricing.billingCycle']);

            AuditLog::record('plan.created', $servicePlan, "Plan creado: {$servicePlan->name}");

            return response()->json([
                'success' => true,
                'message' => 'Service plan created successfully',
                'data' => $this->serializePlan($servicePlan),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating service plan',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update a service plan (Admin only)
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $servicePlan = ServicePlan::where('uuid', $uuid)->first();

            if (!$servicePlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service plan not found'
                ], 404);
            }

            $payload = $this->normalizeProvisionerPayload($request->all(), $servicePlan);

            $validator = Validator::make($payload, [
                'category_id' => 'sometimes|required|exists:categories,id',
                'slug' => 'sometimes|required|string|max:100|unique:service_plans,slug,' . $servicePlan->id,
                'name' => 'sometimes|required|string|max:200',
                'description' => 'nullable|string',
                'base_price' => 'sometimes|required|numeric|min:0',
                'setup_fee' => 'nullable|numeric|min:0',
                'is_popular' => 'boolean',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer',
                'specifications' => 'nullable|array',
                'provisioner' => 'nullable|in:pterodactyl,coolify,hestia,manual',
                'provisioner_config' => 'nullable|array',
                'provisioner_config.egg' => 'nullable|string|max:255',
                'provisioner_config.version' => 'nullable|string|max:100',
                'provisioner_config.environment' => 'nullable|array',
                'provisioner_config.build_pack' => 'nullable|string|in:static,php',
                'provisioner_config.db_enabled' => 'nullable|boolean',
                'provisioner_config.db_type' => 'nullable|string|in:mariadb,mysql,postgresql',
                'pterodactyl_egg' => 'nullable|string|max:255',
                'pterodactyl_version' => 'nullable|string|max:100',
                'game_type' => 'nullable|string|max:50',
                'game_runtime_options' => 'nullable|array',
                'game_config_schema' => 'nullable|array',
                'pterodactyl_nest_id' => 'nullable|integer|min:1',
                'pterodactyl_egg_id' => 'nullable|integer|min:1',
                'pterodactyl_node_id' => 'nullable|integer|min:1',
                'pterodactyl_limits' => 'nullable|array',
                'pterodactyl_feature_limits' => 'nullable|array',
                'pterodactyl_environment' => 'nullable|array',
                'pterodactyl_docker_image' => 'nullable|string|max:255',
                'pterodactyl_startup' => 'nullable|string|max:2000',
                // Claves SAT para CFDI
                'sat_clave_prod_serv' => 'nullable|string|max:10',
                'sat_clave_unidad'    => 'nullable|string|max:3',
                // Tipo de plan y trial
                'plan_type'           => 'nullable|in:paid,free,trial',
                'trial_days'          => 'nullable|integer|min:1|max:365',
                'converts_to_plan_id' => 'nullable|integer|exists:service_plans,id',
                'features' => 'nullable|array',
                'features.*' => 'string|max:500',
                'pricing' => 'nullable|array',
                'pricing.*.billing_cycle_id' => 'required|exists:billing_cycles,id',
                'pricing.*.price' => 'required|numeric|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Update service plan
            $servicePlan->update($this->onlyPlanFields($payload));

            // Update features if provided
            if (array_key_exists('features', $payload) && is_array($payload['features'])) {
                $servicePlan->features()->delete();
                foreach ($payload['features'] as $index => $feature) {
                    PlanFeature::create([
                        'service_plan_id' => $servicePlan->id,
                        'feature' => $feature,
                        'sort_order' => $index
                    ]);
                }
            }

            // Update pricing if provided
            if (array_key_exists('pricing', $payload) && is_array($payload['pricing'])) {
                $incomingCycleIds = [];

                foreach ($payload['pricing'] as $pricing) {
                    $cycleId = (int) $pricing['billing_cycle_id'];

                    // updateOrCreate evita violar la constraint única service_plan_id+billing_cycle_id
                    PlanPricing::updateOrCreate(
                        [
                            'service_plan_id'  => $servicePlan->id,
                            'billing_cycle_id' => $cycleId,
                        ],
                        ['price' => (float) $pricing['price']]
                    );

                    $incomingCycleIds[] = $cycleId;
                }

                // Eliminar ciclos que ya no están en la nueva lista
                if (! empty($incomingCycleIds)) {
                    $servicePlan->pricing()
                        ->whereNotIn('billing_cycle_id', $incomingCycleIds)
                        ->delete();
                }
            }

            DB::commit();

            $this->clearCatalogCaches();

            $servicePlan->load(['category', 'features', 'pricing.billingCycle']);

            AuditLog::record('plan.updated', $servicePlan, "Plan actualizado: {$servicePlan->name}");

            return response()->json([
                'success' => true,
                'message' => 'Service plan updated successfully',
                'data' => $this->serializePlan($servicePlan),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error updating service plan',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Bulk actions: activate, deactivate, delete
     */
    public function bulk(Request $request, string $action): JsonResponse
    {
        $request->validate([
            'uuids'   => 'required|array|min:1',
            'uuids.*' => 'uuid',
        ]);

        $plans = ServicePlan::whereIn('uuid', $request->uuids)->get();

        if ($plans->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No service plans found'], 404);
        }

        try {
            switch ($action) {
                case 'activate':
                    ServicePlan::whereIn('uuid', $request->uuids)->update(['is_active' => true]);
                    $message = 'Plans activated successfully';
                    break;

                case 'deactivate':
                    ServicePlan::whereIn('uuid', $request->uuids)->update(['is_active' => false]);
                    $message = 'Plans deactivated successfully';
                    break;

                case 'delete':
                    $withServices = $plans->filter(fn($p) => $p->services()->count() > 0);
                    if ($withServices->isNotEmpty()) {
                        return response()->json([
                            'success'       => false,
                            'message'       => 'Some plans have existing services and cannot be deleted',
                            'blocked_uuids' => $withServices->pluck('uuid'),
                        ], 400);
                    }
                    ServicePlan::whereIn('uuid', $request->uuids)->delete();
                    $message = 'Plans deleted successfully';
                    break;

                default:
                    return response()->json(['success' => false, 'message' => 'Invalid bulk action'], 400);
            }

            $this->clearCatalogCaches();

            return response()->json(['success' => true, 'message' => $message, 'affected' => $plans->count()]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk action failed',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete a service plan (Admin only)
     */
    public function destroy(string $uuid): JsonResponse
    {
        try {
            $servicePlan = ServicePlan::where('uuid', $uuid)->first();

            if (!$servicePlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service plan not found'
                ], 404);
            }

            // Check if service plan has active services
            if ($servicePlan->services()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete service plan with existing services'
                ], 400);
            }

            $servicePlan->delete();

            AuditLog::record('plan.deleted', $servicePlan, "Plan eliminado: {$servicePlan->name}");

            $this->clearCatalogCaches();

            return response()->json([
                'success' => true,
                'message' => 'Service plan deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting service plan',
                'debug' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function normalizeProvisionerPayload(array $payload, ?ServicePlan $existing = null): array
    {
        if (array_key_exists('provisioner_config', $payload) && is_string($payload['provisioner_config'])) {
            $decoded = json_decode($payload['provisioner_config'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload['provisioner_config'] = is_array($decoded) ? $decoded : null;
            }
        }

        if (array_key_exists('provisioner', $payload)) {
            $payload['provisioner'] = $this->normalizeProvisioner($payload['provisioner']);
        }

        $provisioner = $payload['provisioner'] ?? $existing?->provisioner;
        $existingConfig = $provisioner === $existing?->provisioner
            ? ($existing?->provisioner_config ?? [])
            : [];
        $config = $payload['provisioner_config'] ?? $existingConfig;
        $config = is_array($config) ? $config : [];

        if ($provisioner === 'pterodactyl') {
            if (array_key_exists('pterodactyl_egg', $payload)) {
                $config['egg'] = $payload['pterodactyl_egg'];
            }

            if (array_key_exists('pterodactyl_version', $payload)) {
                $config['version'] = $payload['pterodactyl_version'];
            }

            if (array_key_exists('pterodactyl_environment', $payload)) {
                $config['environment'] = $payload['pterodactyl_environment'];
            }

            if (array_key_exists('environment', $config)) {
                $payload['pterodactyl_environment'] = $config['environment'];
            }

            $payload['provisioner_config'] = $config ?: null;
        } elseif ($provisioner === 'hestia') {
            $config['package'] = $payload['hestia_package'] ?? $config['package'] ?? null;
            $config['web_template'] = $payload['hestia_web_template'] ?? $config['web_template'] ?? 'default';
            $config['dns_template'] = $payload['hestia_dns_template'] ?? $config['dns_template'] ?? 'default';
            $config['mail_enabled'] = $this->booleanConfigValue($payload['hestia_mail_enabled'] ?? $config['mail_enabled'] ?? true);
            $config['db_enabled'] = $this->booleanConfigValue($payload['hestia_db_enabled'] ?? $config['db_enabled'] ?? true);

            if (! empty($config['package'])) {
                $payload['hestia_package'] = $config['package'];
            }

            $payload['provisioner_config'] = $config;
        } elseif ($provisioner === 'coolify') {
            $config['build_pack'] = $payload['provisioner_config']['build_pack'] ?? $config['build_pack'] ?? 'static';
            $config['db_enabled'] = $this->booleanConfigValue($payload['provisioner_config']['db_enabled'] ?? $config['db_enabled'] ?? false);
            $config['db_type']    = $payload['provisioner_config']['db_type'] ?? $config['db_type'] ?? 'mariadb';

            $payload['provisioner_config'] = $config;
        } elseif (array_key_exists('provisioner', $payload) || array_key_exists('provisioner_config', $payload)) {
            $payload['provisioner_config'] = null;
        }

        return $payload;
    }

    private function serializePlan(ServicePlan $plan): array
    {
        $data = $plan->toArray();
        $data['provisioner'] = $plan->provisioner ?: null;
        $data['provisioner_config'] = $plan->normalizedProvisionerConfig();
        $data['pterodactyl_egg'] = $plan->pterodactyl_egg;
        $data['pterodactyl_version'] = $plan->pterodactyl_version;
        $data['pterodactyl_environment'] = $plan->pterodactyl_environment
            ?? data_get($data, 'provisioner_config.environment');

        if ($plan->provisioner === 'coolify') {
            $data['coolify_build_pack'] = $plan->coolify_build_pack;
            $data['coolify_db_enabled'] = $plan->coolify_db_enabled;
        }

        if ($plan->provisioner === 'hestia') {
            $data['hestia_package'] = data_get($data, 'provisioner_config.package');
            $data['hestia_web_template'] = data_get($data, 'provisioner_config.web_template', 'default');
            $data['hestia_dns_template'] = data_get($data, 'provisioner_config.dns_template', 'default');
            $data['hestia_mail_enabled'] = data_get($data, 'provisioner_config.mail_enabled', true);
            $data['hestia_db_enabled'] = data_get($data, 'provisioner_config.db_enabled', true);
        }

        return $data;
    }

    private function normalizeProvisioner(mixed $provisioner): ?string
    {
        $provisioner = is_string($provisioner) ? strtolower(trim($provisioner)) : $provisioner;

        return match ($provisioner) {
            '', null, 'none' => null,
            default => $provisioner,
        };
    }

    private function booleanConfigValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function onlyPlanFields(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'category_id', 'slug', 'name', 'description', 'base_price',
            'setup_fee', 'is_popular', 'is_active', 'sort_order', 'specifications',
            'provisioner', 'provisioner_config', 'hestia_package',
            'game_type', 'game_runtime_options', 'game_config_schema',
            'pterodactyl_nest_id', 'pterodactyl_egg_id', 'pterodactyl_node_id',
            'pterodactyl_limits', 'pterodactyl_feature_limits', 'pterodactyl_environment',
            'pterodactyl_docker_image', 'pterodactyl_startup',
            'sat_clave_prod_serv', 'sat_clave_unidad',
            'plan_type', 'trial_days', 'converts_to_plan_id',
        ]));
    }

    private function clearCatalogCaches(): void
    {
        Cache::forget('service_plans:active:all');
        Cache::forget('service_plans:with_plans');
        Cache::forget('categories:with_plans');

        Category::query()
            ->select(['id', 'slug'])
            ->get()
            ->each(function (Category $category) {
                Cache::forget("service_plans:active:category:{$category->id}");
                Cache::forget("service_plans:active:category_slug:{$category->slug}");
            });
    }
}
