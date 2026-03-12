<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;


trait CacheKeys
{
    /**
     * Generate cache key for user products list
     */
    protected function getUserProductsCacheKey($userId, $category = null, $search = null)
    {
        $key = "user:{$userId}:products";
        
        if ($category) {
            $key .= ":category:{$category}";
        }
        
        if ($search) {
            $key .= ":search:" . md5($search);
        }
        
        return $key;
    }

    /**
     * Generate cache key for single product
     */
    protected function getProductCacheKey($productId)
    {
        return "product:{$productId}";
    }

    /**
     * Generate cache key for public products list
     */
    protected function getPublicProductsCacheKey($page = 1, $category = null, $search = null)
    {
        $key = "public:products:page:{$page}";
        
        if ($category) {
            $key .= ":category:{$category}";
        }
        
        if ($search) {
            $key .= ":search:" . md5($search);
        }
        
        return $key;
    }

    /**
     * Generate cache key for product categories
     */
    protected function getCategoriesCacheKey()
    {
        return "product:categories";
    }

    /**
     * Generate cache key for user profile
     */
    protected function getUserProfileCacheKey($userId)
    {
        return "user:profile:{$userId}";
    }

    /**
     * Generate cache key for farmer profile
     */
    protected function getFarmerProfileCacheKey($userId)
    {
        return "farmer:profile:{$userId}";
    }

    /**
     * Clear all product-related cache for a user
     */
    protected function clearUserProductCache($userId)
    {
        $pattern = "user:{$userId}:products*";
        $this->clearCacheByPattern($pattern);
        $this->clearPublicProductsCache();
    }

    /**
     * Clear public products cache
     */
    protected function clearPublicProductsCache()
    {
        $pattern = "public:products*";
        $this->clearCacheByPattern($pattern);
    }

    /**
     * Clear product categories cache
     */
    protected function clearCategoriesCache()
    {
        cache()->forget($this->getCategoriesCacheKey());
    }

    /**
     * Clear single product cache
     */
    protected function clearProductCache($productId)
    {
        cache()->forget($this->getProductCacheKey($productId));
    }

    /**
     * Clear cache by pattern (if using Redis)
     */
    protected function clearCacheByPattern($pattern)
    {
        if (config('cache.default') === 'redis') {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $keys = $redis->keys($pattern);
            
            if (!empty($keys)) {
                $redis->del($keys);
            }
        }
    }
}