<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Illuminate\Support\Facades\Log;

class CacheService
{
    /**
     * Ensure Redis is the active cache driver
     * 
     * @throws RuntimeException
     * @return void
     */
    protected static function ensureRedisDriver(): void
    {
        if (config('cache.default') !== 'redis') {
            throw new RuntimeException(
                "Current cache driver does not support pattern operations. Please use Redis."
            );
        }
    }

    /**
     * Get Redis connection safely
     * 
     * @return \Illuminate\Redis\Connections\Connection
     */
    protected static function redis()
    {
        self::ensureRedisDriver();
        return Redis::connection();
    }

    /**
     * Test Redis connection
     * 
     * @return bool
     */
    public static function testConnection(): bool
    {
        try {
            // Try to ping Redis
            Redis::connection()->ping();
            return true;
        } catch (\Exception $e) {
            Log::error('Redis connection test failed', [
                'error' => $e->getMessage(),
                'host' => config('database.redis.default.host'),
                'port' => config('database.redis.default.port')
            ]);
            return false;
        }
    }

   /**
 * Get all cache keys matching pattern
 * 
 * @param string $pattern
 * @return array
 */
public static function getKeysByPattern(string $pattern): array
{
    try {
        $redis = self::redis();
        
        // Make sure we're using the correct database
        $redis->select(1); // Select database 1 (where your cache is stored)
        
        $keys = [];
        $cursor = 0;

        do {
            $result = $redis->scan($cursor, [
                'match' => $pattern,
                'count' => 1000,
            ]);

            if ($result === false) {
                break;
            }

            $cursor = $result[0];
            
            // Remove cache prefix if needed
            $prefix = config('cache.prefix', '');
            foreach ($result[1] as $key) {
                if ($prefix && strpos($key, $prefix) === 0) {
                    $keys[] = substr($key, strlen($prefix));
                } else {
                    $keys[] = $key;
                }
            }

        } while ($cursor != 0);

        return $keys;
    } catch (\Exception $e) {
        Log::error('Failed to get keys by pattern', [
            'pattern' => $pattern,
            'error' => $e->getMessage()
        ]);
        return [];
    }
}
    /**
     * Clear all keys matching pattern
     * 
     * @param string $pattern
     * @return int
     */
    public static function clearByPattern(string $pattern): int
    {
        try {
            self::ensureRedisDriver();
            
            // If pattern is '*', use flushdb for efficiency
            if ($pattern === '*') {
                $keyCount = Redis::connection()->dbSize();
                Redis::connection()->flushdb();
                Log::info('Flushed entire Redis database', ['keys_deleted' => $keyCount]);
                return $keyCount;
            }
            
            $keys = self::getKeysByPattern($pattern);
            
            if (empty($keys)) {
                return 0;
            }

            // For Redis, del accepts array of keys
            $deleted = Redis::connection()->del($keys);
            
            Log::info('Cleared cache by pattern', [
                'pattern' => $pattern,
                'keys_found' => count($keys),
                'keys_deleted' => $deleted
            ]);
            
            return $deleted;
        } catch (\Exception $e) {
            Log::error('Failed to clear cache by pattern', [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Delete a specific cache key
     * 
     * @param string $key
     * @return bool
     */
    public static function deleteKey(string $key): bool
    {
        try {
            self::ensureRedisDriver();
            
            $deleted = Redis::connection()->del($key) > 0;
            
            if ($deleted) {
                Log::info('Deleted cache key', ['key' => $key]);
            }
            
            return $deleted;
        } catch (\Exception $e) {
            Log::error('Failed to delete cache key', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get information about a specific cache key
     * 
     * @param string $key
     * @return array|null
     */
    public static function getKeyInfo(string $key): ?array
    {
        try {
            $redis = self::redis();
            
            // Check if key exists
            if (!$redis->exists($key)) {
                return null;
            }

            // Get key type
            $type = $redis->type($key);
            
            // Get TTL
            $ttl = $redis->ttl($key);
            
            // Get value based on type
            $value = null;
            $size = 0;
            
            switch ($type) {
                case 'string':
                    $value = $redis->get($key);
                    $size = strlen($value);
                    
                    // Try to unserialize if it's Laravel cache data
                    $unserialized = @unserialize($value);
                    if ($unserialized !== false) {
                        $value = $unserialized;
                    }
                    break;
                    
                case 'hash':
                    $value = $redis->hgetall($key);
                    $size = strlen(serialize($value));
                    break;
                    
                case 'list':
                    $value = $redis->lrange($key, 0, 10); // Get first 10 items
                    $size = $redis->llen($key);
                    break;
                    
                case 'set':
                    $value = $redis->smembers($key);
                    $size = $redis->scard($key);
                    break;
                    
                case 'zset':
                    $value = $redis->zrange($key, 0, 10, 'WITHSCORES');
                    $size = $redis->zcard($key);
                    break;
            }

            return [
                'key' => $key,
                'type' => $type,
                'ttl' => self::formatTtl($ttl),
                'ttl_seconds' => $ttl,
                'size' => self::formatBytes($size),
                'size_bytes' => $size,
                'exists' => true,
                'preview' => self::formatPreview($value),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get key info', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
 * Get cache statistics
 * 
 * @return array
 */
public static function getStats(): array
{
    try {
        $redis = self::redis();
        $info = $redis->info();
        
        // The info() method returns a nested array structure
        // Access the correct nested keys
        $server = $info['Server'] ?? [];
        $stats = $info['Stats'] ?? [];
        $memory = $info['Memory'] ?? [];
        $keyspace = $info['Keyspace'] ?? [];
        
        // Calculate hit rate
        $hits = $stats['keyspace_hits'] ?? 0;
        $misses = $stats['keyspace_misses'] ?? 0;
        $total = $hits + $misses;
        $hitRate = $total > 0 ? round(($hits / $total) * 100, 2) : 0;
        
        // Get total keys from db1 (or whichever database you're using)
        $db1 = $keyspace['db1'] ?? [];
        $totalKeys = $db1['keys'] ?? $redis->dbsize();
        
        // Get memory info
        $usedMemory = $memory['used_memory'] ?? 0;
        $maxMemory = $memory['maxmemory'] ?? 0;
        $memoryUsagePercent = $maxMemory > 0 ? round(($usedMemory / $maxMemory) * 100, 2) : 0;

        return [
            'used_memory' => $memory['used_memory_human'] ?? 'N/A',
            'used_memory_raw' => $usedMemory,
            'used_memory_peak' => $memory['used_memory_peak_human'] ?? 'N/A',
            'used_memory_peak_raw' => $memory['used_memory_peak'] ?? 0,
            'total_keys' => $totalKeys,
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => $hitRate . '%',
            'hit_rate_raw' => $hitRate,
            'connected_clients' => $info['Clients']['connected_clients'] ?? 0,
            'uptime_days' => $server['uptime_in_days'] ?? 0,
            'uptime_seconds' => $server['uptime_in_seconds'] ?? 0,
            'memory_usage_percent' => $memoryUsagePercent . '%',
            'memory_usage_percent_raw' => $memoryUsagePercent,
            'version' => $server['redis_version'] ?? 'unknown',
            'os' => $server['os'] ?? 'unknown',
            'role' => $info['Replication']['role'] ?? 'unknown',
            'total_commands_processed' => $stats['total_commands_processed'] ?? 0,
            'instantaneous_ops_per_sec' => $stats['instantaneous_ops_per_sec'] ?? 0,
        ];
    } catch (\Exception $e) {
        Log::error('Failed to get cache stats', ['error' => $e->getMessage()]);
        
        return [
            'used_memory' => 'Error',
            'used_memory_raw' => 0,
            'used_memory_peak' => 'Error',
            'used_memory_peak_raw' => 0,
            'total_keys' => 0,
            'hits' => 0,
            'misses' => 0,
            'hit_rate' => '0%',
            'hit_rate_raw' => 0,
            'connected_clients' => 'Error',
            'uptime_days' => 'Error',
            'uptime_seconds' => 0,
            'memory_usage_percent' => '0%',
            'memory_usage_percent_raw' => 0,
            'version' => 'unknown',
            'os' => 'unknown',
            'error' => $e->getMessage()
        ];
    }
}

    /**
     * Flush all cache
     * 
     * @return bool
     */
    public static function flush(): bool
    {
        try {
            $redis = self::redis();
            $redis->flushdb();
            
            Log::info('Cache flushed successfully');
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to flush cache', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Calculate cache hit rate (legacy method)
     */
    private static function calculateHitRate(array $info): string
    {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;

        if ($total === 0) {
            return '0%';
        }

        return number_format(($hits / $total) * 100, 2) . '%';
    }

    /**
     * Format TTL for display
     * 
     * @param int $ttl
     * @return string
     */
    private static function formatTtl(int $ttl): string
    {
        if ($ttl === -1) {
            return 'never expires';
        }
        if ($ttl === -2) {
            return 'expired';
        }
        
        if ($ttl < 60) {
            return $ttl . ' seconds';
        }
        if ($ttl < 3600) {
            $minutes = floor($ttl / 60);
            $seconds = $ttl % 60;
            return $minutes . ' min ' . $seconds . ' sec';
        }
        if ($ttl < 86400) {
            $hours = floor($ttl / 3600);
            $minutes = floor(($ttl % 3600) / 60);
            return $hours . ' hr ' . $minutes . ' min';
        }
        
        $days = floor($ttl / 86400);
        $hours = floor(($ttl % 86400) / 3600);
        return $days . ' days ' . $hours . ' hr';
    }

    /**
     * Format bytes to human readable
     * 
     * @param int $bytes
     * @return string
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Format preview value
     * 
     * @param mixed $value
     * @return mixed
     */
    private static function formatPreview($value)
    {
        if (is_string($value)) {
            return strlen($value) > 200 ? substr($value, 0, 200) . '...' : $value;
        }
        
        if (is_array($value)) {
            if (count($value) > 10) {
                return array_slice($value, 0, 10, true);
            }
        }
        
        return $value;
    }
}