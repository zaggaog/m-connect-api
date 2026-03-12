<?php
// app/Console/Commands/FixMissingProductImages.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class FixMissingProductImages extends Command
{
    protected $signature = 'fix:product-images';
    protected $description = 'Re-upload missing product images to R2';

    public function handle()
    {
        $products = Product::whereNotNull('image')->get();
        
        foreach ($products as $product) {
            $this->info("Checking product ID: {$product->id}");
            
            if (!Storage::disk('r2')->exists($product->image)) {
                $this->warn("  Image missing: {$product->image}");
                
                // If you have local backups, re-upload
                if (Storage::disk('public')->exists($product->image)) {
                    $contents = Storage::disk('public')->get($product->image);
                    Storage::disk('r2')->put($product->image, $contents, 'public');
                    $this->info("  ✅ Re-uploaded from local storage");
                } else {
                    $this->error("  ❌ No local backup found");
                }
            } else {
                $this->info("  ✅ Image exists");
            }
        }
    }
}