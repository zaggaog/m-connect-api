protected $routeMiddleware = [
    // ... existing middleware
    'role' => \App\Http\Middleware\CheckFarmerRole::class,
];

protected $commands = [
    \App\Console\Commands\CacheClearPatternCommand::class,
    \App\Console\Commands\CacheStatsCommand::class,
];