<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'price'
    ];

    // Relationship with Cart
    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    // Relationship with Product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Calculate item total
    public function getTotalAttribute()
    {
        return $this->price * $this->quantity;
    }
}