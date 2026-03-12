<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use App\Services\TracingService;

class TracingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Initialize tracing
        TracingService::init();

        // Listen to database queries
        if (config('app.tracing_enabled', false)) {
            DB::listen(function ($query) {
                $spanId = TracingService::startSpan('DB Query', [
                    'db.system' => 'mysql',
                    'db.statement' => $query->sql,
                    'db.connection' => $query->connectionName,
                ]);

                TracingService::endSpan($spanId, [
                    'db.execution_time_ms' => $query->time,
                ]);
            });
        }
    }
}