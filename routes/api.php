<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\DB;        
use Illuminate\Support\Facades\Schema;    
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PublicProductController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\FarmerOrderController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\FarmerController;
use App\Http\Controllers\Api\CacheController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to Mkulima Connect API',
        'version' => '1.0.0',
        'status' => 'online',
        'endpoints' => [
            'POST /api/auth/register' => 'Register new user',
            'POST /api/auth/login' => 'Login user',
            'POST /api/auth/refresh' => 'Refresh token',
            'GET /api/auth/me' => 'Get current user (requires auth)',
            'POST /api/auth/logout' => 'Logout user (requires auth)',
            'GET /api/auth/check' => 'Check token validity (requires auth)',
        ]
    ]);
});

// Debug route for testing
Route::get('/debug', function () {
    return response()->json([
        'database' => DB::connection()->getPdo() ? 'Connected' : 'Not connected',
        'users_table' => Schema::hasTable('users') ? 'Exists' : 'Missing',
        'user_columns' => Schema::getColumnListing('users'),
        'jwt_secret' => config('jwt.secret') ? 'Set' : 'Not set',
    ]);
});

// Auth routes
Route::prefix('auth')->group(function () {
    // Public routes
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    
    // Protected routes
    Route::middleware('auth:api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/check', [AuthController::class, 'checkToken']);
        
    });
});

// Protected API routes (example)
Route::middleware('auth:api')->group(function () {
    // Buyer routes
    Route::middleware('role:buyer')->prefix('buyer')->group(function () {
        Route::get('/dashboard', function () {
            return response()->json([
                'status' => 'success',
                'message' => 'Welcome to Buyer Dashboard',
                'data' => [
                    'stats' => [
                        'orders' => 0,
                        'cart_items' => 0,
                        'favorites' => 0
                    ]
                ]
            ]);
        });
    });

    // Farmer routes
    Route::middleware('role:farmer')->prefix('farmer')->group(function () {
        Route::get('/dashboard', function () {
            return response()->json([
                'status' => 'success',
                'message' => 'Welcome to Farmer Dashboard',
                'data' => [
                    'stats' => [
                        'products' => 0,
                        'orders' => 0,
                        'revenue' => 0
                    ]
                ]
            ]);
        });
    });
});



Route::middleware('auth:api')->group(function () {
    
    // User profile routes
    Route::prefix('users')->group(function () {
        Route::get('/{id}', [UserController::class, 'show']);
        Route::put('/{id}', [UserController::class, 'update']);
        Route::post('/{id}/avatar', [UserController::class, 'uploadAvatar']);
    });

    // Farmer profile routes
    Route::prefix('farmers')->group(function () {
        Route::get('/{id}/profile', [UserController::class, 'getFarmerProfile']);
        Route::put('/{id}/profile', [UserController::class, 'updateFarmerProfile']);
    });
});






// routes/api.php/products


Route::middleware('auth:api')->group(function () {


    // Product routes
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::get('/products/stats', [ProductController::class, 'stats']);
});



Route::prefix('public')->group(function () {

    // Product listings for buyers
    Route::get('/products', [PublicProductController::class, 'index']);
    Route::get('/products/{id}', [PublicProductController::class, 'show']);
    Route::get('/categories', [PublicProductController::class, 'categories']);
});



// routes/api.php/cart
Route::middleware('auth:api')->group(function () {
    Route::get('/cart', [CartController::class, 'index']);
    Route::post('/cart/add', [CartController::class, 'add']);
    Route::put('/cart/update', [CartController::class, 'update']);
    Route::delete('/cart/remove/{productId}', [CartController::class, 'remove']);
    Route::post('/cart/clear', [CartController::class, 'clear']);


});



// routes/api.php/orders
Route::middleware('auth:api')->group(function () {
    Route::post('/orders/place', [OrderController::class, 'place']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    

    //  Cancel order
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
});




   
//  Farmer routes with role protection
Route::middleware(['auth:api', 'role:farmer'])
    ->prefix('farmer')
    ->group(function () {
        Route::get('/dashboard', [FarmerOrderController::class, 'dashboard']); 
        Route::get('/earnings', [FarmerOrderController::class, 'earnings']);
        Route::get('/orders', [FarmerOrderController::class, 'index']);
        Route::get('/orders/{id}', [FarmerOrderController::class, 'show']);
        Route::put('/orders/{id}/status', [FarmerOrderController::class, 'updateStatus']);
    });





Route::middleware('auth:api')->group(function () {

    // Push token
    Route::post('/push-token', [PushTokenController::class, 'store']);
    Route::delete('/push-token', [PushTokenController::class, 'destroy']);
    

});



Route::get('/test-notification', function() {
    $service = new \App\Services\ExpoPushService();
    
    $testToken = 'ExponentPushToken[TestToken123456789]';
    
    $result = $service->sendToDevice(
        $testToken,
        'Test Notification',
        'This is a test from Laravel!',
        ['type' => 'test']
    );
    
    return response()->json([
        'success' => $result,
        'message' => $result ? 'Notification sent!' : 'Failed to send'
    ]);
});



Route::middleware('auth:api')->group(function () {
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
});




// Farmer API Routes
Route::prefix('farmers')->group(function () {
    // Get farmer by ID
    Route::get('/{id}', [FarmerController::class, 'getFarmer']);
    
    // Get farmer products
    Route::get('/{id}/products', [FarmerController::class, 'getFarmerProducts']);
    
    // Get farmer by email
    Route::get('/email', [FarmerController::class, 'getFarmerByEmail']);
    
    // Update farmer phone number
    Route::put('/{id}/phone', [FarmerController::class, 'updatePhone']);
    
    // Get all farmers
    Route::get('/', [FarmerController::class, 'getAllFarmers']);
});




// Cache API Routes

Route::middleware(['auth:api'])->prefix('cache')->group(function () {
    Route::get('/stats', [CacheController::class, 'stats']);
    Route::get('/test', [CacheController::class, 'testConnection']);
    Route::get('/key-info', [CacheController::class, 'keyInfo']);
    Route::post('/clear', [CacheController::class, 'clear']);
    Route::post('/clear-pattern', [CacheController::class, 'clearPattern']);
    Route::post('/warmup', [CacheController::class, 'warmup']);
});


Route::get('/health', function () {
    return response()->json([
        'status' => 'ok'
    ]);
});