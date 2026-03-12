<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CacheService;

class CacheClearPatternCommand extends Command  // Changed class name
{
    protected $signature = 'cache:clear-pattern {pattern}';
    protected $description = 'Clear cache keys matching a pattern';

    public function handle()
    {
        $pattern = $this->argument('pattern');
        $deleted = CacheService::clearByPattern($pattern);

        $this->info("Cleared {$deleted} cache keys matching: {$pattern}");
    }
}