<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * Get CDN URL for avatar
     */
    private function getAvatarUrl($avatar)
    {
        if (!$avatar) {
            return null;
        }

        try {
            $disk = config('filesystems.default');
            
            // For R2 disk
            if ($disk === 'r2') {
                $baseUrl = rtrim(env('R2_PUBLIC_URL'), '/');
                return $baseUrl . '/' . ltrim($avatar, '/');
            }
            
            // For local public disk (fallback)
            if ($disk === 'public') {
                return asset('storage/' . ltrim($avatar, '/'));
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Error generating avatar URL: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        Log::info('Registration attempt', ['email' => $request->email]);
        
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|in:buyer,farmer',
        ]);

        if ($validator->fails()) {
            Log::warning('Registration validation failed', $validator->errors()->toArray());
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            Log::info('Creating user...');
            
            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role,
            ]);

            Log::info('User created', ['id' => $user->id, 'email' => $user->email]);

            // Generate access token
            $accessToken = JWTAuth::fromUser($user);
            Log::info('Access token generated');
            
            // Generate refresh token
            $refreshToken = Str::random(60);
            Log::info('Refresh token generated');
            
            // Update user with refresh token
            $user->refresh_token = $refreshToken;
            $user->refresh_token_expires_at = Carbon::now()->addDays(30);
            $user->save();
            
            Log::info('User updated with refresh token');

            //  Get CDN URL for avatar
            $avatarUrl = $this->getAvatarUrl($user->avatar);

            return response()->json([
                'status' => 'success',
                'message' => 'User registered successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $avatarUrl, 
                ],
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl', 60) * 60
            ], 201);

        } catch (\Exception $e) {
            Log::error('Registration error: ' . $e->getMessage());
            Log::error('Registration trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login user
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $credentials = $request->only('email', 'password');

        try {
            if (!$token = JWTAuth::attempt($credentials)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid credentials'
                ], 401);
            }

            $user = JWTAuth::user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $refreshToken = Str::random(60);
            
            // Save refresh token to user
            $user->refresh_token = $refreshToken;
            $user->refresh_token_expires_at = Carbon::now()->addDays(30);
            $user->save();

            //  Get CDN URL for avatar
            $avatarUrl = $this->getAvatarUrl($user->avatar);

            return response()->json([
                'status' => 'success',
                'message' => 'Login successful',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $avatarUrl, 
                    'phone' => $user->phone,
                    'location' => $user->location,
                ],
                'accessToken' => $token,
                'refreshToken' => $refreshToken,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl', 60) * 60
            ]);

        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Login failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refreshToken' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Refresh token required'
            ], 422);
        }

        $refreshToken = $request->refreshToken;

        try {
            $user = User::where('refresh_token', $refreshToken)
                ->where('refresh_token_expires_at', '>', Carbon::now())
                ->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid or expired refresh token'
                ], 401);
            }

            $newAccessToken = JWTAuth::fromUser($user);
            $newRefreshToken = Str::random(60);
            
            // Update refresh token
            $user->refresh_token = $newRefreshToken;
            $user->refresh_token_expires_at = Carbon::now()->addDays(30);
            $user->save();

            //  Get CDN URL for avatar
            $avatarUrl = $this->getAvatarUrl($user->avatar);

            return response()->json([
                'status' => 'success',
                'message' => 'Token refreshed successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $avatarUrl, 
                    'phone' => $user->phone,
                    'location' => $user->location,
                ],
                'accessToken' => $newAccessToken,
                'refreshToken' => $newRefreshToken,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl', 60) * 60
            ]);

        } catch (\Exception $e) {
            Log::error('Refresh token error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Token refresh failed'
            ], 500);
        }
    }

    /**
     * Get current user
     */
    public function me(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            //  Get CDN URL for avatar
            $avatarUrl = $this->getAvatarUrl($user->avatar);
            
            return response()->json([
                'status' => 'success',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'avatar' => $avatarUrl, 
                    'location' => $user->location,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 401);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            // Clear refresh token
            $user->refresh_token = null;
            $user->refresh_token_expires_at = null;
            $user->save();
            
            JWTAuth::invalidate(JWTAuth::getToken());
            
            return response()->json([
                'status' => 'success',
                'message' => 'Successfully logged out'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'success',
                'message' => 'Logged out'
            ]);
        }
    }

    /**
     * Check token validity
     */
    public function checkToken(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            //  Get CDN URL for avatar
            $avatarUrl = $this->getAvatarUrl($user->avatar);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Token is valid',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $avatarUrl, 
                    'phone' => $user->phone,
                    'location' => $user->location,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token is invalid'
            ], 401);
        }
    }

    /**
     * Change user password
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'oldPassword' => 'required|string',
            'newPassword' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check($request->oldPassword, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Current password is incorrect'
            ], 401);
        }

        $user->password = Hash::make($request->newPassword);
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully'
        ], 200);
    }
}