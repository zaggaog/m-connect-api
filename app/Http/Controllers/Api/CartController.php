<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    // Get current user's cart
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            Log::info('Fetching cart for user ID: ' . $user->id);
            
            $cart = Cart::with(['items.product'])->firstOrCreate(
                ['user_id' => $user->id]
            );
            
            Log::info('Cart ID: ' . $cart->id . ', Items count: ' . $cart->items->count());
            
            return response()->json([
                'success' => true,
                'cart_id' => $cart->id,
                'items' => $cart->items,
                'total_items' => $cart->items->sum('quantity'),
                'total_amount' => $cart->items->sum(function($item) {
                    return $item->price * $item->quantity;
                })
            ]);
            
        } catch (\Exception $e) {
            Log::error('Cart index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cart',
                'items' => [] // Always return array
            ]);
        }
    }

    // Add item to the cart
    public function add(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity' => 'integer|min:1|max:100'
            ]);
            
            $cart = Cart::firstOrCreate(['user_id' => $request->user()->id]);
            $product = Product::findOrFail($request->product_id);
            
            Log::info('Adding product ' . $product->id . ' to cart ' . $cart->id);
            
            // Find existing item or create new
            $item = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->first();
            
            if ($item) {
                // Update existing item
                $item->increment('quantity', $request->quantity ?? 1);
                $message = 'Cart item updated';
            } else {
                // Create new item
                $item = CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'price' => $product->price,
                    'quantity' => $request->quantity ?? 1
                ]);
                $message = 'Added to cart';
            }
            
            Log::info('Cart item saved: ' . $item->id . ', Qty: ' . $item->quantity);
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'item' => $item->load('product'),
                'cart_id' => $cart->id
            ]);
            
        } catch (\Exception $e) {
            Log::error('Add to cart error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to add item to cart: ' . $e->getMessage()
            ], 500);
        }
    }

    // Update item quantity in the cart
    public function update(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required|exists:products,id',
                'change' => 'required|integer'
            ]);

            $cart = Cart::where('user_id', $request->user()->id)->first();
            
            if (!$cart) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart not found'
                ], 404);
            }
            
            $item = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $request->product_id)
                ->first();

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item not found in cart'
                ], 404);
            }

            $newQty = $item->quantity + $request->change;

            if ($newQty <= 0) {
                $item->delete();
                $message = 'Item removed from cart';
                $quantity = 0;
            } else {
                $item->update(['quantity' => $newQty]);
                $message = 'Cart updated';
                $quantity = $newQty;
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'quantity' => $quantity
            ]);

        } catch (\Exception $e) {
            Log::error('Update cart error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update cart'
            ], 500);
        }
    }

    // Remove an item from the cart
    public function remove(Request $request, $productId)
    {
        try {
            $cart = Cart::where('user_id', $request->user()->id)->first();
            
            if (!$cart) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart not found'
                ], 404);
            }
            
            $deleted = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $productId)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => $deleted ? 'Item removed' : 'Item not found',
                'deleted' => $deleted
            ]);

        } catch (\Exception $e) {
            Log::error('Remove from cart error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove item'
            ], 500);
        }
    }

    // Clear all items from the cart
    public function clear(Request $request)
    {
        try {
            $cart = Cart::where('user_id', $request->user()->id)->first();

            if ($cart) {
                $deleted = $cart->items()->delete();
                Log::info('Cleared cart ' . $cart->id . ', deleted ' . $deleted . ' items');
                
                return response()->json([
                    'success' => true,
                    'message' => 'Cart cleared successfully',
                    'deleted_count' => $deleted
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cart is already empty'
            ]);

        } catch (\Exception $e) {
            Log::error('Clear cart error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cart'
            ], 500);
        }
    }
}