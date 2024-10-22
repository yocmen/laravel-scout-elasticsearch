<?php

namespace Matchish\ScoutElasticSearch\Database\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Cache;

class PageScope implements Scope
{
    /**
     * @var int
     */
    private $page;
    /**
     * @var int
     */
    private $perPage;

    /**
     * PageScope constructor.
     *
     * @param  int  $page
     * @param  int  $perPage
     */
    public function __construct(int $page, int $perPage)
    {
        $this->page = $page;
        $this->perPage = $perPage;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder  $builder
     * @param  Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        $keyName = $model->getKeyName();

        if($keyName === 'id') {
            $builder->forPageAfterId($this->perPage, Cache::get('scout_import_last_id', 0), $keyName);
        } else {
            $builder->forPage($this->page, $this->perPage);
        }
    }
}
