<?php

namespace Vicklr\MaterializedModel\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

trait HasOrderedMaterializedPaths
{
    use HasMaterializedPaths {
        finalizeMove as parentFinalize;
        performChildMove as parentChildMove;
        performRootMove as parentRootMove;
        performNextSiblingMove as parentNextSiblingMove;
        performPreviousSiblingMove as parentPreviousSiblingMove;
    }

    protected static function bootHasOrderedMaterializedPaths(): void
    {
        static::creating(function (Model $node) {
            $node->setAttribute($node->getOrderColumnName(), $node->getMaxOrder() + 1);
        });
    }

    protected function performRootMove(): self
    {
        $this->parentRootMove();

        return $this->setAttribute($this->getOrderColumnName(), $this->getMaxOrder() + 1);
    }

    protected function performChildMove(Model $target): self
    {
        $this->parentChildMove($target);

        return $this->setAttribute($this->getOrderColumnName(), $this->getMaxOrder() + 1);
    }

    protected function performPreviousSiblingMove(Model $target): self
    {
        $this->parentPreviousSiblingMove($target);

        return $this->setAttribute($this->getOrderColumnName(), $target->getOrder());
    }

    protected function performNextSiblingMove(Model $target): self
    {
        $this->parentNextSiblingMove($target);

        return $this->setAttribute($this->getOrderColumnName(), $target->getOrder() + 1);
    }

    protected function finalizeMove(?Model $previousParent = null): void
    {
        $this->parentFinalize($previousParent);

        $this->updateOrdering($previousParent);
    }

    protected function getMaxOrder(): int
    {
        return (int)$this->newQuery()
            ->when(
                $this->parent,
                fn(Builder $query) => $query->where($this->getParentColumnName(), $this->parent->getKey()),
                fn(Builder $query) => $query->whereNull($this->getParentColumnName())
            )
            ->max($this->getOrderColumnName());
    }

    protected function updateOrdering(?Model $previousParent): void
    {
        $this->when(
            $this->parent,
            fn(Builder $query) => $query->where($this->getParentColumnName(), $this->getParentId()),
            fn(Builder $query) => $query->whereNull($this->getParentColumnName())
        )
            ->where($this->getOrderColumnName(), '>=', $this->getOrder())
            ->withoutSelf()
            ->update([$this->getOrderColumnName() => DB::raw($this->getOrderColumnName() . '+1')]);

        $this->reorderChildren($this->parent);

        if (optional($previousParent)->getKey() !== optional($this->parent)->getKey()) {
            $this->reorderChildren($previousParent);
        }

        $this->refresh();
    }

    protected function reorderChildren(?Model $parent): void
    {
        $ordering = 0;
        $this->newMaterializedPathQuery()
            ->when(
                $parent,
                fn(Builder $query) => $query->where($this->getParentColumnName(), $parent->getKey()),
                fn(Builder $query) => $query->whereNull($this->getParentColumnName())
            )->each(function (Model $node) use (&$ordering) {
                $node->update([$node->getOrderColumnName() => ++$ordering]);
            });
    }
}
