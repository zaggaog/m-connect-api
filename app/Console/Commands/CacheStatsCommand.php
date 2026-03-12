<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CacheService;

class CacheStatsCommand extends Command
{
    protected $signature = 'cache:stats';
    protected $description = 'Display cache statistics';

    public function handle()
    {
        $stats = CacheService::getStats();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Memory Used', $stats['used_memory']],
                ['Total Keys', $stats['total_keys']],
                ['Cache Hits', $stats['hits']],
                ['Cache Misses', $stats['misses']],
                ['Hit Rate', $stats['hit_rate']],
            ]
        );
    }
}