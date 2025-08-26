<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    /**
     * Get all active categories
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Category::active();

            $categories = $query->orderBy('sort_order')
                              ->orderBy('name')
                              ->get();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get categories with their service plans
     */
    public function withPlans(Request $request): JsonResponse
    {
        try {
            $categories = Category::active()
                                ->with(['activeServicePlans.features', 'activeServicePlans.pricing.billingCycle'])
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving categories with plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific category by UUID
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $category = Category::where('uuid', $uuid)->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get category by slug
     */
    public function getBySlug(string $slug): JsonResponse
    {
        try {
            $category = Category::where('slug', $slug)
                              ->with(['activeServicePlans.features', 'activeServicePlans.pricing.billingCycle'])
                              ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new category (Admin only)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'slug' => 'required|string|max:50|unique:categories',
                'name' => 'required|string|max:100',
                'description' => 'nullable|string',
                'icon' => 'nullable|string|max:50',
                'color' => 'nullable|string|max:50',
                'bg_color' => 'nullable|string|max:50',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $category = Category::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => $category
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a category (Admin only)
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $category = Category::where('uuid', $uuid)->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'slug' => 'sometimes|required|string|max:50|unique:categories,slug,' . $category->id,
                'name' => 'sometimes|required|string|max:100',
                'description' => 'nullable|string',
                'icon' => 'nullable|string|max:50',
                'color' => 'nullable|string|max:50',
                'bg_color' => 'nullable|string|max:50',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $category->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a category (Admin only)
     */
    public function destroy(string $uuid): JsonResponse
    {
        try {
            $category = Category::where('uuid', $uuid)->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            // Check if category has service plans
            if ($category->servicePlans()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete category with existing service plans'
                ], 400);
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Category deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting category',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

