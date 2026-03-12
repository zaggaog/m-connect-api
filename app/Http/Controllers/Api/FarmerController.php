<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FarmerController extends Controller
{
    /**
     * Get farmer's contact information including phone number
     */
    public function getFarmer($id)
    {
        try {
            // Find farmer by ID with farmer role
            $farmer = User::where('id', $id)
                ->where('role', 'farmer')
                ->first(['id', 'name', 'email', 'phone', 'location', 'role']);
            
            if (!$farmer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Farmer not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $farmer->id,
                    'name' => $farmer->name,
                    'email' => $farmer->email,
                    'phone' => $farmer->phone,
                    'location' => $farmer->location ?? 'Not specified',
                    'role' => $farmer->role,
                    'has_phone' => !empty($farmer->phone)
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get farmer by email (if needed)
     */
    public function getFarmerByEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }
        
        $farmer = User::where('email', $request->email)
            ->where('role', 'farmer')
            ->first(['id', 'name', 'email', 'phone', 'location', 'role']);
        
        if (!$farmer) {
            return response()->json([
                'success' => false,
                'message' => 'Farmer not found with this email'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => $farmer
        ]);
    }
    
    /**
     * Update farmer's phone number
     */
    public function updatePhone(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:10|max:20'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }
        
        try {
            $farmer = User::where('id', $id)
                ->where('role', 'farmer')
                ->first();
            
            if (!$farmer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Farmer not found'
                ], 404);
            }
            
            $farmer->phone = $request->phone;
            $farmer->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Phone number updated successfully',
                'data' => [
                    'phone' => $farmer->phone
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update phone number: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get all farmers with phone numbers
     */
    public function getAllFarmers()
    {
        try {
            $farmers = User::where('role', 'farmer')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'phone', 'location', 'created_at']);
            
            return response()->json([
                'success' => true,
                'data' => $farmers,
                'count' => $farmers->count()
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch farmers: ' . $e->getMessage()
            ], 500);
        }
    }



      /**
     * Get farmer's products
     */
    public function getFarmerProducts($id)
    {
        try {
            // Verify farmer exists
            $farmer = User::where('id', $id)
                ->where('role', 'farmer')
                ->first(['id', 'name']);
            
            if (!$farmer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Farmer not found'
                ], 404);
            }
            
            // Get farmer's products
            $products = Product::where('user_id', $id)
                ->orWhere('farmer_id', $id)
                ->orWhere('seller_id', $id)
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->get([
                    'id',
                    'name',
                    'description',
                    'price',
                    'image',
                    'image_url',
                    'category',
                    'location',
                    'stock_quantity',
                    'created_at'
                ]);
            
            // Format products
            $formattedProducts = $products->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => $product->price,
                    'formatted_price' => 'TZS ' . number_format($product->price, 0),
                    'image' => $product->image ?? $product->image_url,
                    'category' => $product->category,
                    'location' => $product->location,
                    'stock_quantity' => $product->stock_quantity,
                    'created_at' => $product->created_at->format('Y-m-d H:i:s')
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $formattedProducts,
                'count' => $formattedProducts->count(),
                'farmer' => [
                    'id' => $farmer->id,
                    'name' => $farmer->name
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch farmer products: ' . $e->getMessage()
            ], 500);
        }
    }
}
