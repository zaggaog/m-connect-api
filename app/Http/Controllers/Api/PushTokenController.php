<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PushTokenController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        try {
            $userId = Auth::id();
            
            // Direct database update - always works
            $affected = DB::update(
                'UPDATE users SET push_token = ?, updated_at = ? WHERE id = ?',
                [$request->token, now(), $userId]
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Push token saved successfully',
                'affected' => $affected
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save push token',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy()
    {
        try {
            $userId = Auth::id();
            
            $affected = DB::update(
                'UPDATE users SET push_token = NULL, updated_at = ? WHERE id = ?',
                [now(), $userId]
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Push token removed',
                'affected' => $affected
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove push token',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}