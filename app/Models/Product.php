<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    protected $appends = ['image_url'];

    protected $fillable = [
        'name',
        'category',
        'price',
        'quantity',
        'location',
        'description',
        'image',
        'user_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get CDN URL for image
     */
    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image) {
            return null;
        }

        // If already a full URL, return as is
        if (str_starts_with($this->image, 'http')) {
            return $this->image;
        }

        try {
            $disk = config('filesystems.default');
            
            // For R2 disk
            if ($disk === 'r2') {
                $baseUrl = rtrim(env('R2_PUBLIC_URL'), '/');
                return $baseUrl . '/' . ltrim($this->image, '/');
            }
            
            // For local public disk
            if ($disk === 'public') {
                return asset('storage/' . ltrim($this->image, '/'));
            }
            
            // For other disks
            $diskConfig = config("filesystems.disks.{$disk}");
            if (isset($diskConfig['url'])) {
                $baseUrl = rtrim($diskConfig['url'], '/');
                return $baseUrl . '/' . ltrim($this->image, '/');
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function scopeForCurrentUser($query)
    {
        if (Auth::check()) {
            return $query->where('user_id', Auth::id());
        }
        return $query->where('user_id', 0);
    }

    public function scopeByCategory($query, $category)
    {
        if ($category) {
            return $query->where('category', $category);
        }
        return $query;
    }

    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
        return $query;
    }

    protected static function booted()
    {
        static::creating(function ($product) {
            if (empty($product->slug) && isset($product->name)) {
                $product->slug = Str::slug($product->name) . '-' . uniqid();
            }
        });
    }
}