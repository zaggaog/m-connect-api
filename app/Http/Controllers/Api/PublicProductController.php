<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Traits\CacheKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicProductController extends Controller
{
    use CacheKeys;

    /**
     * Cache TTL in seconds (1 hour)
     */
    protected $cacheTtl = 3600;

    /**
     * Display a listing of all products for buyers
     */
    public function index(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $category = $request->category;
            $search = $request->search;
            
            // Generate cache key
            $cacheKey = $this->getPublicProductsCacheKey($page, $category, $search);
            
            // Try to get from cache
            $result = Cache::remember($cacheKey, $this->cacheTtl, function () use ($request) {
                // Eager load user with profile_image if column exists
                $products = Product::with('user:id,name,location') 
                    ->when($request->category, function ($query, $category) {
                        return $query->where('category', $category);
                    })
                    ->when($request->search, function ($query, $search) {
                        return $query->where(function($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                              ->orWhere('description', 'like', "%{$search}%");
                        });
                    })
                    ->latest()
                    ->paginate(20);

                $formattedProducts = $products->map(function ($product) {
                    $response = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'category' => $product->category,
                        'price' => (float) $product->price,
                        'formatted_price' => number_format($product->price) . ' TZS',
                        'quantity' => $product->quantity,
                        'location' => $product->location,
                        'description' => $product->description,
                        'image' => $product->image_url,
                        'farmer_id' => $product->user_id,
                        'farmer_name' => $product->user->name ?? 'Unknown Farmer',
                        'farmer_location' => $product->user->location ?? null,
                        'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                    ];

                    // Safely add farmer_image if column exists
                    if (isset($product->user->profile_image)) {
                        $response['farmer_image'] = $product->user->profile_image_url;
                    }

                    return $response;
                });

                return [
                    'products' => $formattedProducts,
                    'current_page' => $products->currentPage(),
                    'total' => $products->total(),
                    'per_page' => $products->perPage(),
                    'last_page' => $products->lastPage(),
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Products retrieved successfully',
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified product for buyers
     */
    public function show($id)
    {
        try {
            $cacheKey = $this->getProductCacheKey($id) . ':public';
            
            $result = Cache::remember($cacheKey, $this->cacheTtl, function () use ($id) {
                $product = Product::with(['user:id,name,email,phone,location'])
                    ->find($id);

                if (!$product) {
                    return null;
                }

                $farmerData = null;
                if ($product->user) {
                    $farmerData = [
                        'id' => $product->user->id,
                        'name' => $product->user->name,
                        'email' => $product->user->email,
                        'phone' => $product->user->phone ?? null,
                        'location' => $product->user->location ?? null,
                        'rating' => 4.5,
                        'total_products' => $product->user->products()->count(),
                        'joined_date' => $product->user->created_at->format('Y-m-d'),
                    ];

                    // Safely add profile_image if column exists
                    if (isset($product->user->profile_image)) {
                        $farmerData['profile_image'] = $product->user->profile_image_url;
                    }
                }

                return [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'category' => $product->category,
                        'price' => (float) $product->price,
                        'formatted_price' => number_format($product->price) . ' TZS',
                        'quantity' => $product->quantity,
                        'location' => $product->location,
                        'description' => $product->description,
                        'image' => $product->image_url,
                        'farmer_id' => $product->user_id,
                        'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $product->updated_at->format('Y-m-d H:i:s'),
                    ],
                    'farmer' => $farmerData
                ];
            });

            if (!$result) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Product not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Product retrieved successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get product categories
     */
    public function categories()
    {
        try {
            $cacheKey = $this->getCategoriesCacheKey();
            
            $categories = Cache::remember($cacheKey, $this->cacheTtl * 24, function () {
                return Product::distinct('category')
                    ->pluck('category')
                    ->filter()
                    ->values();
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Categories retrieved successfully',
                'data' => [
                    'categories' => $categories
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}