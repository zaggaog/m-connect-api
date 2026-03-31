<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;


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
                $baseUrl = rtrim(env('R2_PUBLIC_URL', 'https://pub-830fc031162b476396c6a260d2baec03.r2.dev'), '/');
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
     * Generate 6-digit OTP
     */
    private function generateOtp()
    {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Send OTP email
     */
    private function sendOtpEmail($user, $otp)
{
    try {
        Log::info('Attempting to send OTP email', [
            'to' => $user->email,
            'otp' => $otp,
            'mailer' => config('mail.default'),
            'sendgrid_key' => config('services.sendgrid.key') ? 'SET' : 'NOT SET'
        ]);

        Mail::send('emails.otp-verification', [
            'user' => $user,
            'otp' => $otp,
            'expires_in' => '10 minutes'
        ], function ($message) use ($user) {
            $message->to($user->email, $user->name)
                ->subject('Your Verification Code - Mkulima Connect');
        });
        
        Log::info('✅ OTP email sent successfully to: ' . $user->email);
        return true;
    } catch (\Exception $e) {
        Log::error('❌ Failed to send OTP email', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return false;
    }
}

    /**
     * Register a new user with OTP
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
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Generate OTP
            $otp = $this->generateOtp();
            $otpExpiresAt = Carbon::now()->addMinutes(10);
            
            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role,
                'email_verified_at' => null,
                'verification_otp' => $otp,
                'verification_otp_expires_at' => $otpExpiresAt,
            ]);

            // Send OTP email
            $this->sendOtpEmail($user, $otp);

            // Generate tokens (optional - you can choose to auto-login after verification)
            $accessToken = JWTAuth::fromUser($user);
            $refreshToken = Str::random(60);
            
            $user->refresh_token = $refreshToken;
            $user->refresh_token_expires_at = Carbon::now()->addDays(30);
            $user->save();

            // Get avatar URL
            $avatarUrl = $this->getAvatarUrl($user->avatar);

            return response()->json([
                'status' => 'success',
                'message' => 'Registration successful. Please verify your email with the OTP sent.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $avatarUrl,
                    'phone' => $user->phone,
                    'location' => $user->location,
                    'email_verified' => false,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl', 60) * 60,
                'requires_verification' => true
            ], 201);

        } catch (\Exception $e) {
            Log::error('Registration error: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify OTP
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::where('email', $request->email)->first();

            // Check if already verified
            if ($user->hasVerifiedEmail()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Email already verified'
                ]);
            }

            // Check OTP
            if ($user->verification_otp !== $request->otp) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid OTP code'
                ], 400);
            }

            // Check if OTP expired
            if (Carbon::now()->greaterThan($user->verification_otp_expires_at)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'OTP has expired. Please request a new one.'
                ], 400);
            }

            // Verify email
            $user->email_verified_at = Carbon::now();
            $user->verification_otp = null;
            $user->verification_otp_expires_at = null;
            $user->save();

            // Generate new token
            $newToken = JWTAuth::fromUser($user);
            $newRefreshToken = Str::random(60);
            
            $user->refresh_token = $newRefreshToken;
            $user->refresh_token_expires_at = Carbon::now()->addDays(30);
            $user->save();

            // Get avatar URL
            $avatarUrl = $this->getAvatarUrl($user->avatar);

            return response()->json([
                'status' => 'success',
                'message' => 'Email verified successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $avatarUrl,
                    'phone' => $user->phone,
                    'location' => $user->location,
                    'email_verified' => true,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
                'accessToken' => $newToken,
                'refreshToken' => $newRefreshToken,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl', 60) * 60
            ]);

        } catch (\Exception $e) {
            Log::error('OTP verification error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Verification failed'
            ], 500);
        }
    }

    /**
     * Resend OTP
     */
    public function resendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email not found'
            ], 404);
        }

        try {
            $user = User::where('email', $request->email)->first();

            if ($user->hasVerifiedEmail()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Email already verified'
                ]);
            }

            // Generate new OTP
            $otp = $this->generateOtp();
            $user->verification_otp = $otp;
            $user->verification_otp_expires_at = Carbon::now()->addMinutes(10);
            $user->save();

            // Send OTP email
            $this->sendOtpEmail($user, $otp);

            return response()->json([
                'status' => 'success',
                'message' => 'New OTP sent successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Resend OTP error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to resend OTP'
            ], 500);
        }
    }

    /**
     * Login - Check if verified
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

            // Check if email is verified
            if (!$user->hasVerifiedEmail()) {
                JWTAuth::invalidate($token);
                
                // Generate new OTP for verification
                $otp = $this->generateOtp();
                $user->verification_otp = $otp;
                $user->verification_otp_expires_at = Carbon::now()->addMinutes(10);
                $user->save();
                
                // Send new OTP
                $this->sendOtpEmail($user, $otp);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Please verify your email address',
                    'requires_verification' => true,
                    'email' => $user->email
                ], 403);
            }

            // Generate refresh token
            $refreshToken = Str::random(60);
            $user->refresh_token = $refreshToken;
            $user->refresh_token_expires_at = Carbon::now()->addDays(30);
            $user->save();

            // Get avatar URL
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
                    'email_verified' => true,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
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
                'message' => 'Login failed'
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

            // Get CDN URL for avatar
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
                    'email_verified' => $user->hasVerifiedEmail(),
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
            
            // Get CDN URL for avatar
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
                    'email_verified' => $user->hasVerifiedEmail(),
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
            
            // Get CDN URL for avatar
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
                    'email_verified' => $user->hasVerifiedEmail(),
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











/**
 * Send password reset OTP
 */
public function forgotPassword(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|email|exists:users,email',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed or incorrect Email',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $user = User::where('email', $request->email)->first();

        // Generate OTP
        $otp = $this->generateOtp();
        $otpExpiresAt = Carbon::now()->addMinutes(10);

        // Store OTP
        $user->reset_password_otp = $otp;
        $user->reset_password_otp_expires_at = $otpExpiresAt;
        $user->save();

        // Send OTP email
        $this->sendPasswordResetOtpEmail($user, $otp);

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset code sent to your email',
            'email' => $user->email
        ]);

    } catch (\Exception $e) {
        Log::error('Forgot password error: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to send reset code'
        ], 500);
    }
}

/**
 * Send password reset OTP email
 */
private function sendPasswordResetOtpEmail($user, $otp)
{
    try {
        Mail::send('emails.password-reset-otp', [
            'user' => $user,
            'otp' => $otp,
            'expires_in' => '10 minutes'
        ], function ($message) use ($user) {
            $message->to($user->email, $user->name)
                ->subject('Password Reset Code - Mkulima Connect');
        });
        
        Log::info('Password reset OTP email sent to: ' . $user->email);
        return true;
    } catch (\Exception $e) {
        Log::error('Failed to send password reset OTP email: ' . $e->getMessage());
        return false;
    }
}

/**
 * Verify password reset OTP
 */
public function verifyResetOtp(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|email|exists:users,email',
        'otp' => 'required|string|size:6',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $user = User::where('email', $request->email)->first();

        // Check OTP
        if ($user->reset_password_otp !== $request->otp) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid OTP code'
            ], 400);
        }

        // Check if OTP expired
        if (Carbon::now()->greaterThan($user->reset_password_otp_expires_at)) {
            return response()->json([
                'status' => 'error',
                'message' => 'OTP has expired. Please request a new one.'
            ], 400);
        }

        // OTP is valid, generate reset token
        $resetToken = Str::random(60);
        
        // Store reset token (you can create a password_resets table or use a simple approach)
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => $resetToken, 'created_at' => Carbon::now()]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'OTP verified successfully',
            'reset_token' => $resetToken,
            'email' => $user->email
        ]);

    } catch (\Exception $e) {
        Log::error('Reset OTP verification error: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Verification failed'
        ], 500);
    }
}

/**
 * Reset password
 */
public function resetPassword(Request $request)
{
    $validator = Validator::make($request->all(), [
        'email' => 'required|email|exists:users,email',
        'reset_token' => 'required|string',
        'password' => 'required|string|min:6|confirmed',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed or incorrect email',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        // Verify reset token
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->reset_token)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid reset token'
            ], 400);
        }

        // Check if token is expired (24 hours)
        if (Carbon::parse($resetRecord->created_at)->addHours(24)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'status' => 'error',
                'message' => 'Reset token has expired'
            ], 400);
        }

        // Update password
        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->reset_password_otp = null;
        $user->reset_password_otp_expires_at = null;
        $user->save();

        // Delete reset token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // Invalidate all user tokens (optional)
        // You might want to invalidate all existing JWTs

        return response()->json([
            'status' => 'success',
            'message' => 'Password reset successfully'
        ]);

    } catch (\Exception $e) {
        Log::error('Reset password error: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Password reset failed'
        ], 500);
    }
}
}