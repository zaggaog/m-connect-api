<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Product;
use App\Services\ExpoPushService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FarmerOrderController extends Controller
{
    protected $pushService;

    public function __construct(ExpoPushService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * GET /api/farmer/orders
     * Get all orders containing farmer's products
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // ✅ Verify user is a farmer
        if ($user->role !== 'farmer') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Farmer access only.'
            ], 403);
        }
        
        $status = $request->query('status');
        
        Log::info("Farmer {$user->id} fetching orders", ['status' => $status]);
        
        // ✅ Get orders that contain at least one of this farmer's products
        $query = Order::whereHas('items.product', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->with(['items.product.user', 'user:id,name,phone,email,location'])
        ->orderBy('created_at', 'desc');

        // Filter by status if provided
        if ($status && in_array($status, ['pending', 'processing', 'completed', 'cancelled'])) {
            $query->where('order_status', $status);
        }

        $orders = $query->get()
            ->map(function ($order) use ($user) {
                return $this->formatOrderForFarmer($order, $user->id);
            })
            // ✅ Remove orders where farmer has no items (edge case)
            ->filter(function ($order) {
                return $order['items_count'] > 0;
            })
            ->values();

        return response()->json([
            'success' => true,
            'orders' => $orders,
            'count' => $orders->count(),
            'total_amount' => $orders->sum('total_amount')
        ]);
    }

    /**
     * GET /api/farmer/orders/{id}
     * Get specific order details (only farmer's portion)
     */
    public function show($id)
    {
        $user = Auth::user();
        
        // ✅ Verify user is a farmer
        if ($user->role !== 'farmer') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Farmer access only.'
            ], 403);
        }
        
        // Find order that contains farmer's products
        $order = Order::whereHas('items.product', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->with(['items.product.user', 'user:id,name,phone,email,location'])
        ->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found or unauthorized'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'order' => $this->formatOrderForFarmer($order, $user->id, true)
        ]);
    }

    /**
     * PUT /api/farmer/orders/{id}/status
     * Update order status (only affects farmer's items) and notify buyer
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,completed,cancelled'
        ]);

        $user = Auth::user();
        
        // ✅ Verify user is a farmer
        if ($user->role !== 'farmer') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Farmer access only.'
            ], 403);
        }
        
        $order = Order::whereHas('items.product', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found or unauthorized'
            ], 404);
        }

        // ⚠️ NOTE: This updates the ENTIRE order status
        // If you want farmer-specific status, you'll need an OrderItem status field
        $oldStatus = $order->order_status;
        $order->update([
            'order_status' => $request->status
        ]);

        Log::info("Farmer {$user->id} changed order {$order->id} status from {$oldStatus} to {$request->status}");

        // ✅ Notify buyer about status change
        $this->notifyBuyerOrderStatusChange($order, $request->status);

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'order' => $this->formatOrderForFarmer($order, $user->id)
        ]);
    }

    /**
     * ✅ Format order showing ONLY farmer's items and totals
     */
    private function formatOrderForFarmer(Order $order, int $farmerId, bool $detailed = false)
    {
        // ✅ Filter items to ONLY this farmer's products
        $farmerItems = $order->items->filter(function ($item) use ($farmerId) {
            return $item->product && $item->product->user_id === $farmerId;
        });

        // ✅ Calculate total ONLY for farmer's items
        $farmerTotal = $farmerItems->sum(function ($item) {
            return $item->price * $item->quantity;
        });

        return [
            'id' => $order->id,
            
            // ✅ CRITICAL: Total reflects ONLY this farmer's products
            'total_amount' => $farmerTotal,
            
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
            
            // Buyer information
            'buyer' => [
                'name' => $order->user->name ?? 'Unknown Customer',
                'phone' => $order->user->phone ?? null,
                'email' => $order->user->email ?? null,
                'location' => $order->user->location ?? null,
            ],
            
            // ✅ Count of ONLY farmer's items
            'items_count' => $farmerItems->count(),
            
            // ✅ ONLY farmer's items
            'items' => $farmerItems->map(function ($item) use ($detailed) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? 'Unknown Product',
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'total' => $item->quantity * $item->price,
                    'product' => $detailed ? [
                        'id' => $item->product->id ?? null,
                        'name' => $item->product->name ?? null,
                        'category' => $item->product->category ?? null,
                        'image_url' => $item->product->image_url ?? null,
                    ] : null,
                ];
            })->values()->toArray(),
        ];
    }

    /**
     * GET /api/farmer/dashboard
     * Get farmer dashboard statistics
     */
    public function dashboard(Request $request)
    {
        $user = Auth::user();
        
        // Get all orders
        $allOrders = Order::whereHas('items.product', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->with(['items.product'])
        ->get();
        
        // Calculate stats
        $completedOrders = $allOrders->where('order_status', 'completed');
        $pendingOrders = $allOrders->where('order_status', 'pending');
        
        // Calculate earnings from COMPLETED orders only
        $totalEarnings = 0;
        foreach ($completedOrders as $order) {
            $farmerItems = $order->items->filter(function ($item) use ($user) {
                return $item->product && $item->product->user_id === $user->id;
            });
            
            $totalEarnings += $farmerItems->sum(function ($item) {
                return $item->price * $item->quantity;
            });
        }
        
        $totalProducts = Product::where('user_id', $user->id)->count();
        
        return response()->json([
            'success' => true,
            'stats' => [
                'total_products' => $totalProducts,
                'completed_orders' => $completedOrders->count(),
                'pending_orders' => $pendingOrders->count(),
                'total_earnings' => $totalEarnings,
            ]
        ]);
    }

    /**
     * GET /api/farmer/earnings
     * Get farmer earnings breakdown
     */
    public function earnings(Request $request)
    {
        $user = Auth::user();
        
        // Get all completed orders
        $completedOrders = Order::whereHas('items.product', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->where('order_status', 'completed')
        ->with(['items.product', 'user:id,name,location'])
        ->orderBy('created_at', 'desc')
        ->get();
        
        // Calculate total earnings
        $totalEarnings = 0;
        $formattedOrders = [];
        
        foreach ($completedOrders as $order) {
            $farmerItems = $order->items->filter(function ($item) use ($user) {
                return $item->product && $item->product->user_id === $user->id;
            });
            
            $orderTotal = $farmerItems->sum(function ($item) {
                return $item->price * $item->quantity;
            });
            
            $totalEarnings += $orderTotal;
            
            $formattedOrders[] = [
                'id' => $order->id,
                'buyer_name' => $order->user->name ?? 'Unknown',
                'buyer_location' => $order->user->location ?? null,
                'total_amount' => $orderTotal,
                'items_count' => $farmerItems->count(),
                'created_at' => $order->created_at,
            ];
        }
        
        // Calculate monthly breakdown
        $now = now();
        $currentMonth = $now->month;
        $currentYear = $now->year;
        
        $thisMonthEarnings = collect($formattedOrders)
            ->filter(function ($order) use ($currentMonth, $currentYear) {
                $orderDate = \Carbon\Carbon::parse($order['created_at']);
                return $orderDate->month === $currentMonth && $orderDate->year === $currentYear;
            })
            ->sum('total_amount');
        
        $lastMonth = $currentMonth === 1 ? 12 : $currentMonth - 1;
        $lastMonthYear = $currentMonth === 1 ? $currentYear - 1 : $currentYear;
        
        $lastMonthEarnings = collect($formattedOrders)
            ->filter(function ($order) use ($lastMonth, $lastMonthYear) {
                $orderDate = \Carbon\Carbon::parse($order['created_at']);
                return $orderDate->month === $lastMonth && $orderDate->year === $lastMonthYear;
            })
            ->sum('total_amount');
        
        // Calculate percentage change
        $percentageChange = 0;
        if ($lastMonthEarnings > 0) {
            $percentageChange = (($thisMonthEarnings - $lastMonthEarnings) / $lastMonthEarnings) * 100;
        } elseif ($thisMonthEarnings > 0) {
            $percentageChange = 100;
        }
        
        return response()->json([
            'success' => true,
            'earnings' => [
                'total' => $totalEarnings,
                'this_month' => $thisMonthEarnings,
                'last_month' => $lastMonthEarnings,
                'percentage_change' => round($percentageChange, 1),
                'completed_orders_count' => count($formattedOrders),
            ],
            'recent_orders' => array_slice($formattedOrders, 0, 20), // Last 20 orders
        ]);
    }

    /**
     * Notify buyer about order status change
     */
    private function notifyBuyerOrderStatusChange($order, $newStatus)
    {
        try {
            $buyer = $order->user;
            
            if ($buyer && !empty($buyer->push_token)) {
                $this->pushService->notifyBuyerOrderStatus(
                    $buyer->push_token,
                    [
                        'order_id' => $order->id,
                        'status' => $newStatus,
                        'order_number' => $order->id,
                        'updated_at' => now()->toISOString(),
                    ]
                );
                
                Log::info('Buyer notified about order status change', [
                    'order_id' => $order->id,
                    'buyer_id' => $buyer->id,
                    'new_status' => $newStatus
                ]);
            } else {
                Log::warning('Cannot notify buyer - no push token', [
                    'order_id' => $order->id,
                    'buyer_id' => $buyer->id ?? 'unknown',
                    'has_token' => !empty($buyer->push_token ?? false)
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify buyer about order status change', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}