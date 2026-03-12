<?php


use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;


// test.php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=====================================\n";
echo "LARAVEL ENVIRONMENT TEST\n";
echo "=====================================\n\n";

echo "Cache Driver: " . config('cache.default') . "\n";
echo "Redis Host: " . config('database.redis.default.host') . "\n";
echo "Redis Port: " . config('database.redis.default.port') . "\n\n";

// Test Redis connection
try {
    $ping = Redis::connection()->ping();
    echo "✅ Redis Connection: OK (PONG)\n";
} catch (\Exception $e) {
    echo "❌ Redis Connection Failed: " . $e->getMessage() . "\n";
}

// Test Cache
try {
    Cache::put('test_redis', 'Redis is working!', 60);
    $value = Cache::get('test_redis');
    Cache::forget('test_redis');
    
    if ($value === 'Redis is working!') {
        echo "✅ Cache Write/Read: OK\n";
    } else {
        echo "❌ Cache Write/Read: Failed\n";
    }
} catch (\Exception $e) {
    echo "❌ Cache Test Failed: " . $e->getMessage() . "\n";
}

// Test CacheService
echo "\n--- CacheService Statistics ---\n";
try {
    $stats = App\Services\CacheService::getStats();
    print_r($stats);
} catch (\Exception $e) {
    echo "❌ CacheService Failed: " . $e->getMessage() . "\n";
}

// Test Database (optional)
echo "\n--- Database Connection ---\n";
try {
    $users = DB::table('users')->count();
    echo "✅ Database Connection: OK (Users: $users)\n";
} catch (\Exception $e) {
    echo "❌ Database Connection Failed: " . $e->getMessage() . "\n";
}

echo "\n=====================================\n";
echo "TEST COMPLETE\n";
echo "=====================================\n";