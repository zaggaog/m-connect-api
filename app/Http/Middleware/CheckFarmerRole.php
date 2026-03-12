<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckFarmerRole
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || $request->user()->role !== 'farmer') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Farmer access only.'
            ], 403);
        }

        return $next($request);
    }
}