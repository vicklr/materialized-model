<?php
namespace Vicklr\MaterializedModel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class HierarchyCollection extends Collection
{
    protected string $className;

    public function setClassName($className): HierarchyCollection
    {
        $this->className = $className;

        return $this;
    }

  public function toHierarchy() {
    $dict = $this->getDictionary();

    uasort($dict, function($a, $b){
        return $a->getOrder() >= $b->getOrder() ? 1 : -1;
    });

    return new Collection($this->hierarchical($dict));
  }

  protected function hierarchical($result) {
    foreach($result as $key => $node) {
        $node->setRelation('children', new Collection);
    }

    $nestedKeys = [];

    foreach($result as $key => $node) {
      $parentKey = $node->getParentId();

      if ( !is_null($parentKey) && array_key_exists($parentKey, $result) ) {
        $result[$parentKey]->children[] = $node;

        $nestedKeys[] = $node->getKey();
      }
    }

    foreach($nestedKeys as $key) {
        unset($result[$key]);
    }

    return $result;
  }

    public function descendants($include_selves = false)
    {
        $prototype = new $this->className();
        return $prototype->newMaterializedPathQuery()
            ->where(function ($query) use ($include_selves, $prototype) {
                $this->each(function (Model $model) use ($query) {
                    $query->orWhere($model->getPathColumnName(), 'like', $model->getPath() . $model->getKey() . '/%');
                });
                $query->when($include_selves, function ($query) use ($prototype) {
                    $query->orWhereIn($prototype->getKeyName(), $this->pluck($prototype->getKeyName()));
                });
            });
    }

    public function descendantsAndSelves()
    {
        return $this->descendants(true);
    }

    public function getDescendantsAndSelves($columns = ['*'])
    {
        return $this->descendantsAndSelves()->get($columns);
    }

    public function getDescendants($columns = ['*'])
    {
        return $this->descendants()->get($columns);
    }

    public function ancestorsAndSelves(): Builder
    {
        $prototype = new $this->className();
        return $prototype->newMaterializedPathQuery()
            ->whereIn(
                $prototype->getKeyName(),
                collect($this->each->map(function (Model $model) {
                    return explode('/', $model->getPath());
                    })->flatten()
                )
                    ->push($this->pluck($prototype->getKey()))
                    ->filter()
                    ->unique()
            );
    }

    public function ancestors()
    {
        return $this->ancestorsAndSelves()->withoutNodes($this);
    }

    public function getAncestorsAndSelves($columns = ['*'])
    {
        return $this->ancestorsAndSelves()->get($columns);
    }

    public function getAncestors($columns = ['*'])
    {
        return $this->ancestors()->get($columns);
    }
}
