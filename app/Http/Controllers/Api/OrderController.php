<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Cart;
use Illuminate\Support\Facades\DB;
use App\Services\ExpoPushService;

class OrderController extends Controller
{
    protected $pushService;

    public function __construct(ExpoPushService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * GET /api/orders
     * List all orders for authenticated user
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $orders = Order::where('user_id', $user->id)
            ->with('items.product')
            ->get()
            ->map(function ($order) {
                return [
                    'id'            => $order->id,
                    'total_amount'  => $order->total_amount,
                    'status'        => $order->order_status,
                    'payment_status'=> $order->payment_status,
                    'items_count'   => $order->items->count(),
                    'items'         => $order->items->map(fn($item) => [
                        'id'           => $item->id,
                        'product_name' => $item->product->name ?? 'Unknown',
                        'price'        => $item->price,
                        'quantity'     => $item->quantity,
                    ]),
                    'created_at'    => $order->created_at,
                ];
            });

        return response()->json([
            'orders' => $orders
        ]);
    }

    /**
     * GET /api/orders/{id}
     * Show order details
     */
    public function show(Request $request, $id)
    {
        $order = Order::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->with('items.product')
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'order' => [
                'id'            => $order->id,
                'total_amount'  => $order->total_amount,
                'status'        => $order->order_status,
                'payment_status'=> $order->payment_status,
                'items_count'   => $order->items->count(),
                'items'         => $order->items->map(fn($item) => [
                    'id'           => $item->id,
                    'product_name' => $item->product->name ?? 'Unknown',
                    'price'        => $item->price,
                    'quantity'     => $item->quantity,
                ]),
                'created_at'    => $order->created_at,
            ]
        ]);
    }

    /**
     * POST /api/orders/place
     * Place an order from the authenticated user's cart
     */
    public function place(Request $request)
    {
        $user = $request->user();

        // Fetch cart with items
        $cart = Cart::where('user_id', $user->id)
            ->with('items.product')
            ->first();

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json([
                'message' => 'Your cart is empty'
            ], 400);
        }

        try {
            $order = DB::transaction(function () use ($cart, $user) {
                $totalAmount = $cart->items->sum(fn($item) => $item->price * $item->quantity);

                $order = Order::create([
                    'user_id'        => $user->id,
                    'total_amount'   => $totalAmount,
                    'order_status'   => 'pending',
                    'payment_status' => 'unpaid',
                ]);

                foreach ($cart->items as $item) {
                    $order->items()->create([
                        'product_id' => $item->product_id,
                        'quantity'   => $item->quantity,
                        'price'      => $item->price,
                    ]);
                }

                // Clear cart
                $cart->items()->delete();

                return $order;
            });

            // ✅ Notify farmers
            $this->notifyFarmersAboutOrder($order);

            return response()->json([
                'message' => 'Order placed successfully',
                'order' => $order->load('items.product')
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to place order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Notify all farmers involved in the order
     */
    private function notifyFarmersAboutOrder($order)
    {
        try {
            // Load order with items and their products
            $order->load('items.product.user');
            
            // Get unique farmer IDs from the order items
            $farmerIds = $order->items->map(function ($item) {
                return $item->product->user_id ?? null;
            })->filter()->unique();
            
            if ($farmerIds->isEmpty()) {
                \Illuminate\Support\Facades\Log::warning('No farmers found for order', [
                    'order_id' => $order->id
                ]);
                return;
            }
            
            foreach ($farmerIds as $farmerId) {
                $farmer = \App\Models\User::find($farmerId);
                
                if ($farmer && !empty($farmer->push_token)) {
                    // Get items for this specific farmer
                    $farmerItems = $order->items->filter(function ($item) use ($farmerId) {
                        return $item->product && $item->product->user_id == $farmerId;
                    });
                    
                    // Calculate total for this farmer
                    $farmerTotal = $farmerItems->sum(function ($item) {
                        return $item->price * $item->quantity;
                    });
                    
                    // Get buyer name
                    $buyerName = $order->user->name ?? 'Customer';
                    
                    // Send notification
                    $this->pushService->notifyFarmerNewOrder(
                        $farmer->push_token,
                        [
                            'order_id' => $order->id,
                            'buyer_name' => $buyerName,
                            'total_amount' => $farmerTotal,
                            'items_count' => $farmerItems->count(),
                            'order_date' => $order->created_at->toISOString(),
                        ]
                    );
                    
                    \Illuminate\Support\Facades\Log::info('Farmer notified about new order', [
                        'farmer_id' => $farmerId,
                        'order_id' => $order->id,
                        'token_preview' => substr($farmer->push_token, 0, 30) . '...'
                    ]);
                } else {
                    \Illuminate\Support\Facades\Log::warning('Farmer has no push token', [
                        'farmer_id' => $farmerId,
                        'has_token' => !empty($farmer->push_token ?? false)
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify farmers', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * POST /api/orders/{id}/cancel
     * Cancel an order
     */
    public function cancel(Request $request, $id)
    {
        $order = Order::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Order not found'
            ], 404);
        }

        if (!in_array($order->order_status, ['pending', 'processing'])) {
            return response()->json([
                'message' => 'Order cannot be cancelled'
            ], 400);
        }

        $order->update([
            'order_status' => 'cancelled'
        ]);
        
        // Notify buyer about cancellation
        $this->notifyBuyerOrderCancelled($order);

        return response()->json([
            'message' => 'Order cancelled successfully'
        ]);
    }

    /**
     * POST /api/orders/{id}/update-status
     * Update order status (for farmers/admin)
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,confirmed,processing,shipped,delivered,completed,cancelled'
        ]);

        $order = Order::find($id);
        
        if (!$order) {
            return response()->json([
                'message' => 'Order not found'
            ], 404);
        }

        // Check if user has permission (either buyer or farmer involved in order)
        $user = $request->user();
        $isBuyer = $order->user_id == $user->id;
        $isFarmer = $order->items()->whereHas('product', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->exists();
        
        if (!$isBuyer && !$isFarmer) {
            return response()->json([
                'message' => 'Unauthorized to update this order'
            ], 403);
        }

        $oldStatus = $order->order_status;
        $order->update([
            'order_status' => $request->status
        ]);

        // Notify relevant parties about status change
        $this->notifyOrderStatusChange($order, $oldStatus, $request->status);

        return response()->json([
            'message' => 'Order status updated successfully',
            'order' => $order->fresh()
        ]);
    }

    /**
     * Notify buyer about order cancellation
     */
    private function notifyBuyerOrderCancelled($order)
    {
        try {
            $buyer = $order->user;
            
            if ($buyer && !empty($buyer->push_token)) {
                $this->pushService->notifyBuyerOrderStatus(
                    $buyer->push_token,
                    [
                        'order_id' => $order->id,
                        'status' => 'cancelled',
                        'order_number' => $order->id,
                        'updated_at' => now()->toISOString(),
                    ]
                );
                
                \Illuminate\Support\Facades\Log::info('Buyer notified about order cancellation', [
                    'order_id' => $order->id,
                    'buyer_id' => $buyer->id
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify buyer about cancellation', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify about order status change
     */
    private function notifyOrderStatusChange($order, $oldStatus, $newStatus)
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
                        'previous_status' => $oldStatus,
                    ]
                );
                
                \Illuminate\Support\Facades\Log::info('Buyer notified about order status change', [
                    'order_id' => $order->id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to notify about status change', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}