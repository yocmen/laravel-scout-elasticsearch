<?php

namespace Matchish\ScoutElasticSearch\Jobs\Stages;

use Elasticsearch\Client;
use Illuminate\Support\Facades\Cache;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * @internal
 */
final class CleanLastId
{
    public function handle(): void
    {
        // Clean Last id
        Cache::forget('scout_import_last_id');
    }

    public function title(): string
    {
        return 'Clean Last Id';
    }

    public function estimate(): int
    {
        return 1;
    }
}
