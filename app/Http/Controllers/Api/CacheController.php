<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Artisan;

class CacheController extends Controller
{
    /**
     * Get cache statistics
     */
    public function stats()
    {
        $stats = CacheService::getStats();
        
        // Check if Redis is working
        $isConnected = CacheService::testConnection();
        
        return response()->json([
            'status' => 'success',
            'data' => array_merge($stats, [
                'cache_driver' => config('cache.default'),
                'redis_connected' => $isConnected,
            ])
        ]);
    }
    /**
     * Clear all cache
     */
    public function clear()
    {
        try {
            Cache::flush();

            return response()->json([
                'status' => 'success',
                'message' => 'All cache cleared successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear cache', ['error' => $e->getMessage()]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clear cache'
            ], 500);
        }
    }

    /**
     * Clear cache by pattern
     */
    public function clearPattern(Request $request)
    {
        $request->validate([
            'pattern' => 'sometimes|string|max:255'
        ]);

        try {
            $pattern = $request->input('pattern', '*');
            $deleted = CacheService::clearByPattern($pattern);

            return response()->json([
                'status' => 'success',
                'message' => "Cleared {$deleted} cache keys matching '{$pattern}'",
                'deleted' => $deleted
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear cache pattern', [
                'pattern' => $pattern ?? '*',
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clear cache pattern: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Warm up cache (pre-populate frequently accessed data)
     */
    public function warmup()
    {
        try {
            // Implement your warmup logic here
            $warmedItems = $this->performWarmup();

            return response()->json([
                'status' => 'success',
                'message' => "Cache warmed up successfully. {$warmedItems} items cached.",
                'warmed_items' => $warmedItems
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to warm up cache', ['error' => $e->getMessage()]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to warm up cache'
            ], 500);
        }
    }

    /**
     * Example warmup implementation
     */
    private function performWarmup(): int
    {
        $count = 0;
        
        // Example: Cache frequently accessed data
        // $categories = Category::all();
        // Cache::put('all_categories', $categories, now()->addDay());
        // $count++;
        
        return $count;
    }
}