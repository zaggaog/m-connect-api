<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\CacheKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class UserController extends Controller
{
    use CacheKeys;

    protected $cacheTtl = 3600;

    /**
     * Get storage disk (R2 or public)
     */
    private function getStorageDisk()
    {
        return Storage::disk(config('filesystems.default'));
    }

    /**
     * Generate URL for file path
     */
    private function getFileUrl($path)
    {
        if (!$path) {
            return null;
        }

        try {
            $disk = config('filesystems.default');
            
            // For R2 disk
            if ($disk === 'r2') {
                $baseUrl = rtrim(env('R2_PUBLIC_URL'), '/');
                return $baseUrl . '/' . ltrim($path, '/');
            }
            
            // For local public disk
            if ($disk === 'public') {
                return asset('storage/' . ltrim($path, '/'));
            }
            
            // For S3 or other disks, try to use the URL from config
            $diskConfig = config("filesystems.disks.{$disk}");
            if (isset($diskConfig['url'])) {
                $baseUrl = rtrim($diskConfig['url'], '/');
                return $baseUrl . '/' . ltrim($path, '/');
            }
            
            // Fallback
            return null;
        } catch (\Exception $e) {
            Log::error('Error generating file URL: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update user profile
     */
    public function update(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            
            if ($request->user()->id != $user->id) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
                'phone' => 'sometimes|string|max:20|nullable',
                'avatar' => 'sometimes|string|nullable',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = $request->only(['name', 'email', 'phone']);
            
            if ($request->has('avatar') && filter_var($request->avatar, FILTER_VALIDATE_URL)) {
                $updateData['avatar'] = $request->avatar;
            }
            
            $user->update($updateData);

            // Clear cache
            Cache::forget($this->getUserProfileCacheKey($user->id));
            if ($user->role === 'farmer') {
                Cache::forget($this->getFarmerProfileCacheKey($user->id));
            }
            
            // Get avatar URL
            $avatarUrl = $this->getFileUrl($user->avatar);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Profile updated successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'avatar' => $avatarUrl,
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Update profile error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload avatar to CDN
     */
    public function uploadAvatar(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            
            if ($request->user()->id != $user->id) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Delete old avatar from CDN
            if ($user->avatar) {
                if ($this->getStorageDisk()->exists($user->avatar)) {
                    $this->getStorageDisk()->delete($user->avatar);
                }
            }

            // Upload to CDN
            $file = $request->file('avatar');
            $filename = 'avatars/' . $user->id . '_' . uniqid() . '_' . time() . '.' . $file->getClientOriginalExtension();
            
            $this->getStorageDisk()->put($filename, file_get_contents($file), 'public');

            // Update user
            $user->avatar = $filename;
            $user->save();

            // Clear cache
            Cache::forget($this->getUserProfileCacheKey($user->id));
            if ($user->role === 'farmer') {
                Cache::forget($this->getFarmerProfileCacheKey($user->id));
            }

            // Get avatar URL
            $avatarUrl = $this->getFileUrl($filename);

            Log::info('Avatar uploaded to CDN', [
                'user_id' => $user->id,
                'storage' => config('filesystems.default'),
                'url' => $avatarUrl,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Avatar uploaded successfully',
                'avatar_url' => $avatarUrl,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $avatarUrl,
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Upload avatar error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Upload failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user profile
     */
    public function show(Request $request, $id)
    {
        try {
            $cacheKey = $this->getUserProfileCacheKey($id);
            
            $userData = Cache::remember($cacheKey, $this->cacheTtl, function () use ($id, $request) {
                $user = User::findOrFail($id);
                
                if ($request->user()->id != $user->id) {
                    return ['unauthorized' => true];
                }

                // Get avatar URL
                $avatarUrl = $this->getFileUrl($user->avatar);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $avatarUrl,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ];
            });

            if (isset($userData['unauthorized'])) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            return response()->json([
                'status' => 'success',
                'user' => $userData
            ]);
            
        } catch (\Exception $e) {
            Log::error('Get user error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch user data'
            ], 500);
        }
    }

    /**
     * Get farmer profile
     */
    public function getFarmerProfile(Request $request, $id)
    {
        try {
            $cacheKey = $this->getFarmerProfileCacheKey($id);
            
            $farmerData = Cache::remember($cacheKey, $this->cacheTtl, function () use ($id, $request) {
                $user = User::findOrFail($id);
                
                if ($request->user()->id != $user->id) {
                    return ['unauthorized' => true];
                }

                if ($user->role !== 'farmer') {
                    return ['not_farmer' => true];
                }

                // Get avatar URL
                $avatarUrl = $this->getFileUrl($user->avatar);

                return [
                    'farm_name' => $user->farm_name,
                    'location' => $user->location,
                    'phone' => $user->phone,
                    'is_verified' => $user->is_verified,
                    'verification_status' => $user->verification_status,
                    'farm_description' => $user->farm_description,
                    'farm_size' => $user->farm_size,
                    'specialty' => $user->specialty,
                    'avatar' => $avatarUrl,
                ];
            });

            if (isset($farmerData['unauthorized'])) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            if (isset($farmerData['not_farmer'])) {
                return response()->json(['status' => 'error', 'message' => 'User is not a farmer'], 400);
            }

            return response()->json([
                'status' => 'success',
                'farmer' => $farmerData
            ]);
            
        } catch (\Exception $e) {
            Log::error('Get farmer profile error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch farmer data'
            ], 500);
        }
    }

    /**
     * Update farmer profile
     */
    public function updateFarmerProfile(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            
            if ($request->user()->id != $user->id) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            if ($user->role !== 'farmer') {
                return response()->json(['status' => 'error', 'message' => 'User is not a farmer'], 400);
            }

            $validator = Validator::make($request->all(), [
                'farm_name' => 'sometimes|string|max:255',
                'location' => 'sometimes|string|max:255',
                'phone' => 'sometimes|string|max:20',
                'farm_description' => 'sometimes|string|nullable',
                'farm_size' => 'sometimes|string|max:100',
                'specialty' => 'sometimes|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $farmerFields = ['farm_name', 'location', 'phone', 'farm_description', 'farm_size', 'specialty'];
            foreach ($farmerFields as $field) {
                if ($request->has($field)) {
                    $user->$field = $request->$field;
                }
            }

            if (($request->has('location') || $request->has('phone')) && !$user->is_verified) {
                $user->verification_status = 'pending';
            }

            $user->save();

            // Clear cache
            Cache::forget($this->getFarmerProfileCacheKey($user->id));

            return response()->json([
                'status' => 'success',
                'message' => 'Farmer profile updated successfully',
                'farmer' => [
                    'farm_name' => $user->farm_name,
                    'location' => $user->location,
                    'phone' => $user->phone,
                    'is_verified' => $user->is_verified,
                    'verification_status' => $user->verification_status,
                    'farm_description' => $user->farm_description,
                    'farm_size' => $user->farm_size,
                    'specialty' => $user->specialty,
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Update farmer profile error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}