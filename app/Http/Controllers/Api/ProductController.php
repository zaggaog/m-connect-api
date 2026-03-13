<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\TracingService;
use App\Traits\CacheKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;


class ProductController extends Controller
{
    use CacheKeys;

    /**
     * Cache TTL in seconds (1 hour)
     */
    protected $cacheTtl = 60;

    /**
     * Get storage disk (R2 or public)
     */
    private function getStorageDisk()
    {
        return Storage::disk(config('filesystems.default'));
    }

    /**
     * Generate URL for file path
     */
    private function getFileUrl($path)
    {
        if (!$path) {
            return null;
        }

        try {
            $disk = config('filesystems.default');
            
            // For R2 disk
            if ($disk === 'r2') {
                $baseUrl = rtrim(env('R2_PUBLIC_URL'), '/');
                return $baseUrl . '/' . ltrim($path, '/');
            }
            
            // For local public disk
            if ($disk === 'public') {
                return asset('storage/' . ltrim($path, '/'));
            }
            
            // For S3 or other disks, try to use the URL from config
            $diskConfig = config("filesystems.disks.{$disk}");
            if (isset($diskConfig['url'])) {
                $baseUrl = rtrim($diskConfig['url'], '/');
                return $baseUrl . '/' . ltrim($path, '/');
            }
            
            // Fallback
            return null;
        } catch (\Exception $e) {
            Log::error('Error generating file URL: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Display a listing of the products for the authenticated user.
     */
    public function index(Request $request)
    {
        $spanId = TracingService::startSpan('Get Products List', [
            'user.id' => Auth::id(),
            'has_category_filter' => $request->has('category'),
            'has_search_filter' => $request->has('search'),
        ]);

        try {
            $userId = Auth::id();
            $category = $request->category;
            $search = $request->search;
            
            $cacheKey = $this->getUserProductsCacheKey($userId, $category, $search);
            
            $products = Cache::remember($cacheKey, $this->cacheTtl, function () use ($userId, $category, $search) {
                return Product::where('user_id', $userId)
                    ->when($category, function ($query, $category) {
                        return $query->where('category', $category);
                    })
                    ->when($search, function ($query, $search) {
                        return $query->where(function($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                              ->orWhere('description', 'like', "%{$search}%");
                        });
                    })
                    ->latest()
                    ->get()
                    ->map(function ($product) {
                        // Get image URL
                        $imageUrl = $this->getFileUrl($product->image);

                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'category' => $product->category,
                            'price' => (float) $product->price,
                            'formatted_price' => number_format($product->price) . ' TZS',
                            'quantity' => $product->quantity,
                            'location' => $product->location,
                            'description' => $product->description,
                            'image' => $imageUrl,
                            'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                            'updated_at' => $product->updated_at->format('Y-m-d H:i:s'),
                        ];
                    });
            });

            TracingService::endSpan($spanId, [
                'products.count' => count($products),
                'filter.category' => $request->category ?? 'all',
                'filter.search' => $request->search ? 'yes' : 'no',
                'response.status' => 'success',
                'cache.hit' => Cache::has($cacheKey) ? 'yes' : 'no',
                'cdn.enabled' => config('filesystems.default') === 'r2',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Products retrieved successfully',
                'data' => [
                    'products' => $products,
                    'total' => count($products),
                ]
            ]);
            
        } catch (\Exception $e) {
            TracingService::recordException($e);
            TracingService::endSpan($spanId, [
                'error' => true,
                'error.message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(Request $request)
    {
        $spanId = TracingService::startSpan('Create Product', [
            'user.id' => Auth::id(),
            'has_image' => $request->hasFile('image'),
        ]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        if ($validator->fails()) {
            TracingService::endSpan($spanId, ['validation.failed' => true]);
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            if (!Auth::check()) {
                TracingService::endSpan($spanId, ['auth.failed' => true]);
                return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
            }

            $userId = Auth::id();

            // Upload to CDN (R2 or local)
            $imagePath = null;
            if ($request->hasFile('image')) {
              TracingService::addEvent('Image Upload Started', [
                  'file.size' => $request->file('image')->getSize(),
              ]);

             $image = $request->file('image');
             $filename = 'products/' . uniqid() . '_' . time() . '.' . $image->getClientOriginalExtension();
    
           // Upload to storage and get the actual path
          $imagePath = $this->getStorageDisk()->putFileAs(
           'products',
            $image,
            basename($filename),
           'public'
    );

    TracingService::addEvent('Image Upload Completed', [
        'file.path' => $imagePath,
        'storage' => config('filesystems.default'),
    ]);
}
            // Create product
            $product = Product::create([
                'name' => $request->name,
                'category' => $request->category,
                'price' => $request->price,
                'quantity' => $request->quantity,
                'location' => $request->location,
                'description' => $request->description,
                'image' => $imagePath,
                'user_id' => $userId,
            ]);

            // Clear related cache
            $this->clearUserProductCache($userId);
            $this->clearPublicProductsCache();
            $this->clearCategoriesCache();

            // Get image URL
            $imageUrl = $this->getFileUrl($imagePath);

            $responseData = [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category,
                'price' => (float) $product->price,
                'formatted_price' => number_format($product->price) . ' TZS',
                'quantity' => $product->quantity,
                'location' => $product->location,
                'description' => $product->description,
                'image' => $imageUrl,
                'created_at' => $product->created_at->format('Y-m-d H:i:s'),
            ];

            TracingService::endSpan($spanId, [
                'product.id' => $product->id,
                'image.uploaded' => $imagePath !== null,
                'cdn.used' => config('filesystems.default') === 'r2',
                'response.status' => 'success',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Product created successfully',
                'data' => $responseData
            ], 201);

        } catch (\Exception $e) {
            // Delete from CDN if upload failed
            if (isset($imagePath) && $this->getStorageDisk()->exists($imagePath)) {
                $this->getStorageDisk()->delete($imagePath);
            }

            TracingService::recordException($e);
            TracingService::endSpan($spanId, ['error' => true]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified product.
     */
    public function show($id)
    {
        $spanId = TracingService::startSpan('Get Product Detail', [
            'user.id' => Auth::id(),
            'product.id' => $id,
        ]);

        try {
            $cacheKey = $this->getProductCacheKey($id);
            
            $productData = Cache::remember($cacheKey, $this->cacheTtl, function () use ($id) {
                $product = Product::where('user_id', Auth::id())->find($id);

                if (!$product) {
                    return null;
                }

                // Get image URL
                $imageUrl = $this->getFileUrl($product->image);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'price' => (float) $product->price,
                    'formatted_price' => number_format($product->price) . ' TZS',
                    'quantity' => $product->quantity,
                    'location' => $product->location,
                    'description' => $product->description,
                    'image' => $imageUrl,
                    'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $product->updated_at->format('Y-m-d H:i:s'),
                ];
            });

            if (!$productData) {
                TracingService::endSpan($spanId, ['product.found' => false]);
                return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
            }

            TracingService::endSpan($spanId, [
                'product.found' => true,
                'cache.hit' => Cache::has($cacheKey) ? 'yes' : 'no',
                'cdn.enabled' => config('filesystems.default') === 'r2',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Product retrieved successfully',
                'data' => $productData
            ]);

        } catch (\Exception $e) {
            TracingService::recordException($e);
            TracingService::endSpan($spanId, ['error' => true]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified product in storage.
     */
    public function update(Request $request, $id)
    {
        $spanId = TracingService::startSpan('Update Product', [
            'user.id' => Auth::id(),
            'product.id' => $id,
            'has_new_image' => $request->hasFile('image'),
        ]);

        Log::info('🔧 UPDATE PRODUCT API CALLED', [
            'product_id' => $id,
            'user_id' => Auth::id(),
            'has_file' => $request->hasFile('image'),
        ]);

        try {
            $userId = Auth::id();
            $product = Product::where('user_id', $userId)->find($id);

            if (!$product) {
                TracingService::endSpan($spanId, ['product.found' => false]);
                return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'category' => 'sometimes|required|string|max:255',
                'price' => 'sometimes|required|numeric|min:0',
                'quantity' => 'sometimes|required|string|max:255',
                'location' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            ]);

            if ($validator->fails()) {
                TracingService::endSpan($spanId, ['validation.failed' => true]);
                return response()->json([
                    'status' => 'error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $imagePath = $product->image;

            // Handle new image upload to CDN
            if ($request->hasFile('image')) {
                TracingService::addEvent('New Image Upload');
                
                // Delete old image from CDN
                if ($product->image && $this->getStorageDisk()->exists($product->image)) {
                    $this->getStorageDisk()->delete($product->image);
                }

                // Upload new image to CDN
                $image = $request->file('image');
                $filename = 'products/' . uniqid() . '_' . time() . '.' . $image->getClientOriginalExtension();
                $this->getStorageDisk()->put($filename, file_get_contents($image), 'public');
                $imagePath = $filename;
            } 
            elseif ($request->input('image') === '' || $request->input('image') === null) {
                TracingService::addEvent('Image Removal');
                
                // Delete image from CDN
                if ($product->image && $this->getStorageDisk()->exists($product->image)) {
                    $this->getStorageDisk()->delete($product->image);
                }
                $imagePath = null;
            }

            // Update product
            $product->update([
                'name' => $request->filled('name') ? $request->name : $product->name,
                'category' => $request->filled('category') ? $request->category : $product->category,
                'price' => $request->filled('price') ? $request->price : $product->price,
                'quantity' => $request->filled('quantity') ? $request->quantity : $product->quantity,
                'location' => $request->filled('location') ? $request->location : $product->location,
                'description' => $request->filled('description') ? $request->description : $product->description,
                'image' => $imagePath,
            ]);

            // Clear cache
            $this->clearUserProductCache($userId);
            $this->clearPublicProductsCache();
            $this->clearProductCache($product->id);
            $this->clearCategoriesCache();

            // Get image URL
            $imageUrl = $this->getFileUrl($imagePath);

            TracingService::endSpan($spanId, [
                'product.updated' => true,
                'cdn.used' => config('filesystems.default') === 'r2',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Product updated successfully',
                'data' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'price' => (float) $product->price,
                    'formatted_price' => number_format($product->price) . ' TZS',
                    'quantity' => $product->quantity,
                    'location' => $product->location,
                    'description' => $product->description,
                    'image' => $imageUrl,
                    'updated_at' => $product->updated_at->format('Y-m-d H:i:s'),
                ]
            ]);

        } catch (\Exception $e) {
            TracingService::recordException($e);
            TracingService::endSpan($spanId, ['error' => true]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy($id)
    {
        $spanId = TracingService::startSpan('Delete Product', [
            'user.id' => Auth::id(),
            'product.id' => $id,
        ]);

        try {
            $userId = Auth::id();
            $product = Product::where('user_id', $userId)->find($id);

            if (!$product) {
                TracingService::endSpan($spanId, ['product.found' => false]);
                return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
            }

            $productName = $product->name;

            // Delete from CDN
            if ($product->image && $this->getStorageDisk()->exists($product->image)) {
                $this->getStorageDisk()->delete($product->image);
                TracingService::addEvent('Image Deleted from CDN', [
                    'image_path' => $product->image,
                ]);
            }

            $product->delete();

            // Clear cache
            $this->clearUserProductCache($userId);
            $this->clearPublicProductsCache();
            $this->clearProductCache($id);
            $this->clearCategoriesCache();

            TracingService::endSpan($spanId, [
                'product.deleted' => true,
                'product.name' => $productName,
                'cdn.used' => config('filesystems.default') === 'r2',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Product deleted successfully'
            ]);

        } catch (\Exception $e) {
            TracingService::recordException($e);
            TracingService::endSpan($spanId, ['error' => true]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    
}