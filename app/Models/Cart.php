<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = ['user_id'];

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship with CartItems
    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    // Helper to calculate total items count
    public function getTotalItemsAttribute()
    {
        return $this->items->sum('quantity');
    }

    // Helper to calculate cart total amount
    public function getTotalAmountAttribute()
    {
        return $this->items->sum(function ($item) {
            return $item->price * $item->quantity;
        });
    }

    // Add item to cart
    public function addItem($productId, $quantity = 1, $price = null)
    {
        $product = Product::find($productId);
        
        if (!$product) {
            return null;
        }

        return $this->items()->updateOrCreate(
            ['product_id' => $productId],
            [
                'quantity' => DB::raw("COALESCE(quantity, 0) + $quantity"),
                'price' => $price ?? $product->price
            ]
        );
    }

    // Clear all items from cart
    public function clear()
    {
        return $this->items()->delete();
    }
}