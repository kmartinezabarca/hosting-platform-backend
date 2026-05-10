<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Models\ServicePlan;
use App\Models\PlanFeature;
use App\Models\PlanPricing;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
                ->withCount('features')
                ->select([
                    'id', 'uuid', 'name', 'slug',
                    'category_id', 'base_price', 'setup_fee',
                    'is_active', 'is_popular', 'sort_order',
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
                'data' => $servicePlan
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
            $validator = Validator::make($request->all(), [
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
                'provisioner' => 'nullable|in:none,pterodactyl,manual',
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
            $servicePlan = ServicePlan::create($request->only([
                'category_id', 'slug', 'name', 'description', 'base_price',
                'setup_fee', 'is_popular', 'is_active', 'sort_order', 'specifications',
                'provisioner', 'game_type', 'game_runtime_options', 'game_config_schema',
                'pterodactyl_nest_id', 'pterodactyl_egg_id', 'pterodactyl_node_id',
                'pterodactyl_limits', 'pterodactyl_feature_limits', 'pterodactyl_environment',
                'pterodactyl_docker_image', 'pterodactyl_startup',
            ]));

            // Create features if provided
            if ($request->has('features')) {
                foreach ($request->features as $index => $feature) {
                    PlanFeature::create([
                        'service_plan_id' => $servicePlan->id,
                        'feature' => $feature,
                        'sort_order' => $index
                    ]);
                }
            }

            // Create pricing if provided
            if ($request->has('pricing')) {
                foreach ($request->pricing as $pricing) {
                    PlanPricing::create([
                        'service_plan_id' => $servicePlan->id,
                        'billing_cycle_id' => $pricing['billing_cycle_id'],
                        'price' => $pricing['price']
                    ]);
                }
            }

            DB::commit();

            $servicePlan->load(['category', 'features', 'pricing.billingCycle']);

            return response()->json([
                'success' => true,
                'message' => 'Service plan created successfully',
                'data' => $servicePlan
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

            $validator = Validator::make($request->all(), [
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
                'provisioner' => 'nullable|in:none,pterodactyl,manual',
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
            $servicePlan->update($request->only([
                'category_id', 'slug', 'name', 'description', 'base_price',
                'setup_fee', 'is_popular', 'is_active', 'sort_order', 'specifications',
                'provisioner', 'game_type', 'game_runtime_options', 'game_config_schema',
                'pterodactyl_nest_id', 'pterodactyl_egg_id', 'pterodactyl_node_id',
                'pterodactyl_limits', 'pterodactyl_feature_limits', 'pterodactyl_environment',
                'pterodactyl_docker_image', 'pterodactyl_startup',
            ]));

            // Update features if provided
            if ($request->has('features')) {
                $servicePlan->features()->delete();
                foreach ($request->features as $index => $feature) {
                    PlanFeature::create([
                        'service_plan_id' => $servicePlan->id,
                        'feature' => $feature,
                        'sort_order' => $index
                    ]);
                }
            }

            // Update pricing if provided
            if ($request->has('pricing')) {
                $incomingCycleIds = [];

                foreach ($request->pricing as $pricing) {
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

            $servicePlan->load(['category', 'features', 'pricing.billingCycle']);

            return response()->json([
                'success' => true,
                'message' => 'Service plan updated successfully',
                'data' => $servicePlan
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
}
