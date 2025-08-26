<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    /**
     * Get all active products
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Product::active();

            // Filter by service type if provided
            if ($request->has('service_type')) {
                $query->serviceType($request->service_type);
            }

            $products = $query->orderBy('sort_order')
                            ->orderBy('name')
                            ->get();

            return response()->json([
                'success' => true,
                'data' => $products
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific product by UUID
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $product = Product::where('uuid', $uuid)->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products by service type
     */
    public function getByServiceType(string $serviceType): JsonResponse
    {
        try {
            $products = Product::active()
                             ->serviceType($serviceType)
                             ->orderBy('sort_order')
                             ->orderBy('name')
                             ->get();

            return response()->json([
                'success' => true,
                'data' => $products
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new product (Admin only)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'service_type' => 'required|in:web_hosting,vps,game_server,domain',
                'game_type' => 'nullable|string|max:50',
                'specifications' => 'required|array',
                'pricing' => 'required|array',
                'setup_fee' => 'nullable|numeric|min:0',
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

            $product = Product::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a product (Admin only)
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $product = Product::where('uuid', $uuid)->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'service_type' => 'sometimes|required|in:web_hosting,vps,game_server,domain',
                'game_type' => 'nullable|string|max:50',
                'specifications' => 'sometimes|required|array',
                'pricing' => 'sometimes|required|array',
                'setup_fee' => 'nullable|numeric|min:0',
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

            $product->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a product (Admin only)
     */
    public function destroy(string $uuid): JsonResponse
    {
        try {
            $product = Product::where('uuid', $uuid)->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            // Check if product has active services
            if ($product->services()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete product with existing services'
                ], 400);
            }

            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

