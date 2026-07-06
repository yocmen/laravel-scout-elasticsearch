<?php

namespace Matchish\ScoutElasticSearch\Searchable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Matchish\ScoutElasticSearch\Database\Scopes\ChunkScope;

final class DefaultImportSource implements ImportSource
{
    const DEFAULT_CHUNK_SIZE = 500;

    /**
     * @var string
     */
    private $className;
    /**
     * @var array
     */
    private $scopes;

    /**
     * DefaultImportSource constructor.
     *
     * @param  string  $className
     * @param  array  $scopes
     */
    public function __construct(string $className, array $scopes = [])
    {
        $this->className = $className;
        $this->scopes = $scopes;
    }

    public function syncWithSearchUsingQueue(): ?string
    {
        return $this->model()->syncWithSearchUsingQueue();
    }

    public function syncWithSearchUsing(): ?string
    {
        return $this->model()->syncWithSearchUsing();
    }

    public function searchableAs(): string
    {
        return $this->model()->searchableAs();
    }

    public function chunked(): Collection
    {
        $chunkSize = (int) config('scout.chunk.searchable', self::DEFAULT_CHUNK_SIZE);
        $key = $this->model()->getQualifiedKeyName();

        // Pull only the ordered primary keys instead of counting rows and
        // paginating by offset. Selecting a single indexed column stays cheap
        // even on huge tables, and it lets each chunk seek by key range
        // (WHERE key > ? AND key <= ?) rather than OFFSET, so keyset paging
        // costs the same for the first chunk and the last one. Imports no
        // longer slow down as they progress through a large table.
        //
        // reorder()->orderBy($key) drops any competing ORDER BY (e.g. from a
        // model global scope or makeAllSearchableUsing) so the key sequence is
        // strictly monotonic. Without it the boundaries would be sorted by the
        // wrong column and the id ranges would skip or duplicate rows.
        $keys = $this->newQuery()->reorder()->orderBy($key)->pluck($key);

        if ($keys->isEmpty()) {
            return collect();
        }

        // The last key of every chunk is its inclusive upper bound; the upper
        // bound of the previous chunk is this chunk's exclusive lower bound.
        // Using real keys (not arithmetic offsets) keeps the ranges correct
        // even when keys are sparse because of deletes, and lets each chunk
        // stage run independently on a queue with no shared cursor state.
        $bounds = $keys->chunk($chunkSize)->map->last()->values();

        return $bounds->map(function ($end, $index) use ($bounds) {
            $start = $index === 0 ? null : $bounds->get($index - 1);
            $chunkScope = new ChunkScope($start, $end);

            return new static($this->className, array_merge($this->scopes, [$chunkScope]));
        });
    }

    /**
     * @return mixed
     */
    private function model()
    {
        return new $this->className;
    }

    private function newQuery(): Builder
    {
        $query = $this->className::__callStatic('makeAllSearchableUsing', [$this->model()->newQuery()]);

        $softDelete = $this->className::usesSoftDelete() && config('scout.soft_delete', false);

        $query
            ->when($softDelete, function ($query) {
                return $query->withTrashed();
            })
            ->orderBy($this->model()->getQualifiedKeyName());

        $scopes = $this->scopes;

        return collect($scopes)->reduce(function ($instance, $scope) {
            $instance->withGlobalScope(get_class($scope), $scope);

            return $instance;
        }, $query);
    }

    public function get(): EloquentCollection
    {
        return $this->newQuery()->get();
    }
}
