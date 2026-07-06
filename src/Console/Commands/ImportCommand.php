<?php

declare(strict_types=1);

namespace Matchish\ScoutElasticSearch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Matchish\ScoutElasticSearch\ElasticSearch\Config\Config;
use Matchish\ScoutElasticSearch\Jobs\Import;
use Matchish\ScoutElasticSearch\Jobs\QueueableJob;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;
use Matchish\ScoutElasticSearch\Searchable\ImportSourceFactory;
use Matchish\ScoutElasticSearch\Searchable\SearchableListFactory;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;

final class ImportCommand extends Command
{
    /**
     * @inheritdoc
     */
    protected $signature = 'scout:import {searchable?* : The name of the searchable}';
    /**
     * @inheritdoc
     */
    protected $description = 'Create new index and import all searchable into the one';

    /**
     * @inheritdoc
     */
    public function handle(): void
    {
        $this->searchableList((array) $this->argument('searchable'))
        ->each(function ($searchable) {
            $this->import($searchable);
        });
    }

    private function searchableList(array $argument): Collection
    {
        return collect($argument)->whenEmpty(function () {
            $factory = new SearchableListFactory(app()->getNamespace(), app()->path());

            return $factory->make();
        });
    }

    private function import(string $searchable): void
    {
        $sourceFactory = app(ImportSourceFactory::class);
        $source = $sourceFactory::from($searchable);
        $job = new Import($source);
        $job->timeout = Config::queueTimeout();

        $queued = (bool) config('scout.queue');

        if ($queued) {
            $job = (new QueueableJob())->chain([$job]);
            $job->timeout = Config::queueTimeout();
        }

        $bar = (new ProgressBarFactory($this->output))->create();
        $job->withProgressReport($bar);

        $startMessage = trans('scout::import.start', ['searchable' => "<comment>$searchable</comment>"]);
        $this->line($startMessage);

        // Snapshot the state before the import so we can report what changed.
        // Only meaningful for synchronous imports; when queued the work runs
        // elsewhere and the alias still points at the previous index here.
        $elasticsearch = app(Client::class);
        $previousCount = $queued ? null : $this->documentCount($elasticsearch, $source->searchableAs());
        $expectedCount = $queued ? null : $source->count();
        $startedAt = microtime(true);

        /* @var ImportSource $source */
        dispatch($job)->allOnQueue($source->syncWithSearchUsingQueue())
            ->allOnConnection($source->syncWithSearchUsing());

        if (! $queued) {
            $newCount = $this->documentCount($elasticsearch, $source->searchableAs());
            $this->summary($searchable, $previousCount, $expectedCount, $newCount, microtime(true) - $startedAt);
        }

        $doneMessage = trans($queued ? 'scout::import.done.queue' : 'scout::import.done', [
            'searchable' => $searchable,
        ]);
        $this->output->success($doneMessage);
    }

    /**
     * Number of documents currently reachable through the given index/alias,
     * or null when it does not exist yet (first import).
     */
    private function documentCount(Client $elasticsearch, string $index): ?int
    {
        try {
            return (int) $elasticsearch->count(['index' => $index])['count'];
        } catch (Missing404Exception $e) {
            return null;
        }
    }

    /**
     * Render a table comparing the previous index with the freshly built one.
     */
    private function summary(
        string $searchable,
        ?int $previousCount,
        ?int $expectedCount,
        ?int $newCount,
        float $duration
    ): void {
        $this->newLine();
        $this->line(trans('scout::import.summary', ['searchable' => "<comment>$searchable</comment>"]));
        $this->table(['Metric', 'Value'], [
            [trans('scout::import.summary.previous'), $this->formatCount($previousCount)],
            [trans('scout::import.summary.expected'), $this->formatCount($expectedCount)],
            [trans('scout::import.summary.indexed'), $this->formatCount($newCount)],
            [trans('scout::import.summary.difference'), $this->formatDifference($previousCount, $newCount)],
            [trans('scout::import.summary.duration'), sprintf('%.2fs', $duration)],
        ]);

        if ($expectedCount !== null && $newCount !== null && $newCount < $expectedCount) {
            $this->warn(trans('scout::import.mismatch', [
                'indexed' => number_format($newCount),
                'expected' => number_format($expectedCount),
                'missing' => number_format($expectedCount - $newCount),
            ]));
        }
    }

    private function formatCount(?int $count): string
    {
        return $count === null ? trans('scout::import.summary.none') : number_format($count);
    }

    private function formatDifference(?int $previousCount, ?int $newCount): string
    {
        if ($previousCount === null || $newCount === null) {
            return 'n/a';
        }

        $difference = $newCount - $previousCount;
        $sign = $difference > 0 ? '+' : ($difference < 0 ? '-' : '');

        return $sign.number_format(abs($difference));
    }
}
