<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'total_amount',
        'order_status',
        'payment_status'
    ];

    protected $attributes = [
        'order_status' => 'pending',
        'payment_status' => 'unpaid'
    ];

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship with OrderItems
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // Helper method to calculate total
    public function calculateTotal()
    {
        return $this->items->sum(function ($item) {
            return $item->price * $item->quantity;
        });
    }
}