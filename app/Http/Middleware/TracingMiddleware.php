<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TracingService;

class TracingMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Start tracing span
        $spanId = TracingService::startSpan(
            $request->method() . ' ' . $request->path(),
            [
                'http.method' => $request->method(),
                'http.url' => $request->fullUrl(),
                'http.route' => $request->route()?->uri() ?? 'unknown',
                'http.user_agent' => $request->userAgent(),
                'http.client_ip' => $request->ip(),
            ]
        );

        try {
            $response = $next($request);

            // Add response attributes
            TracingService::endSpan($spanId, [
                'http.status_code' => $response->getStatusCode(),
                'http.response_size' => strlen($response->getContent()),
            ]);

            return $response;
        } catch (\Throwable $e) {
            TracingService::recordException($e);
            TracingService::endSpan($spanId, [
                'error' => true,
                'http.status_code' => 500,
            ]);

            throw $e;
        }
    }
}