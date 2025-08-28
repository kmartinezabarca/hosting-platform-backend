<?php

namespace App\Http\Controllers;

use App\Models\AddOn;
use App\Models\ServicePlan;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AddOnController extends Controller
{
    /**
     * Get all add-ons
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = AddOn::query();

            // Filter by active status if provided
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search by name or description
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $addOns = $query->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $addOns
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving add-ons',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active add-ons for public use
     */
    public function active(): JsonResponse
    {
        try {
            $addOns = AddOn::where('is_active', true)
                          ->orderBy('name')
                          ->get();

            return response()->json([
                'success' => true,
                'data' => $addOns
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving active add-ons',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific add-on by UUID
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $addOn = AddOn::where('uuid', $uuid)->first();

            if (!$addOn) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add-on not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $addOn
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving add-on',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new add-on (Admin only)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'slug' => 'required|string|max:255|unique:add_ons',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:500',
                'price' => 'required|numeric|min:0',
                'currency' => 'nullable|string|max:3',
                'is_active' => 'boolean',
                'metadata' => 'nullable|array',
                'service_plans' => 'nullable|array',
                'service_plans.*' => 'exists:service_plans,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Create add-on
            $addOn = AddOn::create($request->only([
                'slug', 'name', 'description', 'price', 'currency', 'is_active', 'metadata'
            ]));

            // Attach to service plans if provided
            if ($request->has('service_plans')) {
                $planData = [];
                foreach ($request->service_plans as $planId) {
                    $planData[$planId] = ['is_default' => false];
                }
                $addOn->plans()->attach($planData);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Add-on created successfully',
                'data' => $addOn
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating add-on',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an add-on (Admin only)
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $addOn = AddOn::where('uuid', $uuid)->first();

            if (!$addOn) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add-on not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'slug' => 'sometimes|required|string|max:255|unique:add_ons,slug,' . $addOn->id,
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string|max:500',
                'price' => 'sometimes|required|numeric|min:0',
                'currency' => 'nullable|string|max:3',
                'is_active' => 'boolean',
                'metadata' => 'nullable|array',
                'service_plans' => 'nullable|array',
                'service_plans.*' => 'exists:service_plans,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Update add-on
            $addOn->update($request->only([
                'slug', 'name', 'description', 'price', 'currency', 'is_active', 'metadata'
            ]));

            // Update service plans relationship if provided
            if ($request->has('service_plans')) {
                $planData = [];
                foreach ($request->service_plans as $planId) {
                    $planData[$planId] = ['is_default' => false];
                }
                $addOn->plans()->sync($planData);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Add-on updated successfully',
                'data' => $addOn
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error updating add-on',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an add-on (Admin only)
     */
    public function destroy(string $uuid): JsonResponse
    {
        try {
            $addOn = AddOn::where('uuid', $uuid)->first();

            if (!$addOn) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add-on not found'
                ], 404);
            }

            // Check if add-on is being used in any active services
            $activeServices = DB::table('service_add_ons')
                               ->join('services', 'service_add_ons.service_id', '=', 'services.id')
                               ->where('service_add_ons.add_on_id', $addOn->id)
                               ->where('services.status', 'active')
                               ->count();

            if ($activeServices > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete add-on that is being used in active services'
                ], 400);
            }

            $addOn->delete();

            return response()->json([
                'success' => true,
                'message' => 'Add-on deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting add-on',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get add-ons for a specific service plan
     */
    public function getByServicePlan(string $planUuid): JsonResponse
    {
        try {
            $servicePlan = ServicePlan::where('uuid', $planUuid)->first();

            if (!$servicePlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service plan not found'
                ], 404);
            }

            $addOns = $servicePlan->addOns()
                                 ->where('is_active', true)
                                 ->orderBy('name')
                                 ->get();

            return response()->json([
                'success' => true,
                'data' => $addOns
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving add-ons for service plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Attach add-on to service plan
     */
    public function attachToPlan(Request $request, string $uuid): JsonResponse
    {
        try {
            $addOn = AddOn::where('uuid', $uuid)->first();

            if (!$addOn) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add-on not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'service_plan_id' => 'required|exists:service_plans,id',
                'is_default' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $addOn->plans()->syncWithoutDetaching([
                $request->service_plan_id => [
                    'is_default' => $request->boolean('is_default', false)
                ]
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Add-on attached to service plan successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error attaching add-on to service plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Detach add-on from service plan
     */
    public function detachFromPlan(Request $request, string $uuid): JsonResponse
    {
        try {
            $addOn = AddOn::where('uuid', $uuid)->first();

            if (!$addOn) {
                return response()->json([
                    'success' => false,
                    'message' => 'Add-on not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'service_plan_id' => 'required|exists:service_plans,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $addOn->plans()->detach($request->service_plan_id);

            return response()->json([
                'success' => true,
                'message' => 'Add-on detached from service plan successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error detaching add-on from service plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

