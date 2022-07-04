<?php

namespace Vicklr\MaterializedModel\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Vicklr\MaterializedModel\Events\MaterializedModelMovedEvent;
use Vicklr\MaterializedModel\Exceptions\MoveNotPossibleException;
use Vicklr\MaterializedModel\HierarchyCollection;

trait HasMaterializedPaths
{
    /**
     * Column name to store the reference to parent's node.
     */
    protected string $parentColumn = 'parent_id';

    /**
     * Column name for path.
     */
    protected string $pathColumn = 'path';

    /**
     * Column name for depth field.
     */
    protected string $depthColumn = 'depth';

    /**
     * Column name for ordering field.
     */
    protected string $orderColumn = 'ordering';

    /**
     * Whether to enforce and automatically manage ordering
     */
    protected bool $autoOrdering = true;

    protected static function bootHasMaterializedPaths(): void
    {
        static::creating(function (Model $node) {
            if ($node->autoOrdering) {
                $node->setAttribute($node->getOrderColumnName(), $node->getMaxOrder() + 1);
            }
        });

        static::saving(function (Model $node) {
            $node->setPath()
                ->setDepth();
        });

        static::saved(function (Model $node) {
            if ($node->wasChanged('parent_id')) {
                $node->rebuild();
            }
        });
    }

    public function getParentColumnName(): string
    {
        return $this->parentColumn;
    }

    public function getQualifiedParentColumnName(): string
    {
        return $this->getTable() . '.' . $this->getParentColumnName();
    }

    public function getParentId()
    {
        return $this->getAttribute($this->getparentColumnName());
    }

    public function getPathColumnName(): string
    {
        return $this->pathColumn;
    }

    public function getQualifiedPathColumnName(): string
    {
        return $this->getTable() . '.' . $this->getPathColumnName();
    }

    public function getPath()
    {
        return $this->getAttribute($this->getPathColumnName());
    }

    public function getDepthColumnName(): string
    {
        return $this->depthColumn;
    }

    public function getQualifiedDepthColumnName(): string
    {
        return $this->getTable() . '.' . $this->getDepthColumnName();
    }

    public function getDepth()
    {
        return $this->getAttribute($this->getDepthColumnName());
    }

    public function getOrderColumnName(): string
    {
        return $this->orderColumn ?: $this->getPathColumnName();
    }

    public function getQualifiedOrderColumnName(): string
    {
        return $this->getTable() . '.' . $this->getOrderColumnName();
    }

    public function getOrder()
    {
        return $this->getAttribute($this->getOrderColumnName());
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(get_class($this), $this->getParentColumnName())
            ->withoutGlobalScopes();
    }

    public function children(): HasMany
    {
        return $this->hasMany(get_class($this), $this->getParentColumnName())
            ->withoutGlobalScopes()
            ->orderBy($this->getOrderColumnName());
    }

    public function newMaterializedPathQuery(): Builder
    {
        return $this->newQuery()
            ->orderBy($this->getQualifiedOrderColumnName());
    }

    public function newCollection(array $models = []): HierarchyCollection
    {
        return (new HierarchyCollection($models))->setClassName(static::class);
    }

    public static function all($columns = ['*'])
    {
        $instance = new static;

        return $instance->newQuery()
            ->orderBy($instance->getQualifiedOrderColumnName())->get($columns);
    }

    public static function root()
    {
        return static::roots()->first();
    }

    public static function roots()
    {
        $instance = new static;

        return $instance->newQuery()
            ->whereNull($instance->getParentColumnName())
            ->orderBy($instance->getQualifiedOrderColumnName());
    }

    public function rebuild($save_self = false): void
    {
        if ($save_self) {
            $this->save();
        }
        if ($this->children()->count()) {
            $this->children()->get()->each->rebuild(true);
        }
    }

    protected function scopeWithoutNode($query, $node)
    {
        return $query->where($node->getKeyName(), '!=', $node->getKey());
    }

    protected function scopeWithoutNodes($query, Collection $nodes)
    {
        return $query->whereNotIn($nodes->first()->getKeyName(), $nodes->pluck($nodes->first()->getKeyName()));
    }

    protected function scopeWithoutSelf($query)
    {
        return $this->scopeWithoutNode($query, $this);
    }

    protected function scopeLimitDepth($query, $limit)
    {
        $depth = $this->getDepth();
        $max = $depth + $limit;
        $scopes = [$depth, $max];

        return $query->whereBetween($this->getDepthColumnName(), [min($scopes), max($scopes)]);
    }

    public function isRoot(): bool
    {
        return is_null($this->getParentId());
    }

    public function isChild(): bool
    {
        return !$this->isRoot();
    }

    public function getRoot(): Model
    {
        if ($this->exists) {
            return $this->ancestorsAndSelf()->whereNull($this->getParentColumnName())->first();
        }
        $parentId = $this->getParentId();

        if (!is_null($parentId) && $currentParent = static::find($parentId)) {
            return $currentParent->getRoot();
        }
        return $this;
    }

    public function ancestorsAndSelf(): Builder
    {
        return $this->newMaterializedPathQuery()
            ->whereIn(
                $this->getKeyName(),
                collect(explode('/', $this->getPath()))
                    ->push($this->getKey())
                    ->filter()
                    ->unique()
            );
    }

    public function getAncestorsAndSelf($columns = ['*']): Collection
    {
        return $this->ancestorsAndSelf()->get($columns);
    }

    public function ancestors(): Builder
    {
        return $this->ancestorsAndSelf()->withoutSelf();
    }

    public function getAncestors($columns = ['*']): Collection
    {
        return $this->ancestors()->get($columns);
    }

    public function siblingsAndSelf(): Builder
    {
        return $this->newMaterializedPathQuery()->where($this->getParentColumnName(), $this->getParentId());
    }

    public function getSiblingsAndSelf($columns = ['*']): Collection
    {
        return $this->siblingsAndSelf()->get($columns);
    }

    public function siblings(): Builder
    {
        return $this->siblingsAndSelf()->withoutSelf();
    }

    public function getSiblings($columns = ['*']): Collection
    {
        return $this->siblings()->get($columns);
    }

    public function descendants($include_self = false): Builder
    {
        return $this->newMaterializedPathQuery()
            ->where(function ($query) use ($include_self) {
                $query->where($this->getPathColumnName(), 'like', $this->getPath() . $this->getKey() . '/%');
                $query->when($include_self, function ($query) {
                    $query->orWhere($this->getKeyName(), $this->getKey());
                });
            });
    }

    public function descendantsAndSelf(): Builder
    {
        return $this->descendants(true);
    }

    public function getDescendantsAndSelf($columns = ['*']): Collection
    {
        if (is_array($columns)) {
            return $this->descendantsAndSelf()->get($columns);
        }

        $arguments = func_get_args();

        $limit = intval(array_shift($arguments));
        $columns = array_shift($arguments) ?: [];

        return $this->descendantsAndSelf()->limitDepth($limit)->get($columns);
    }

    public function getDescendants($columns = ['*']): Collection
    {
        if (is_array($columns)) {
            return $this->descendants()->get($columns);
        }

        $arguments = func_get_args();

        $limit = intval(array_shift($arguments));
        $columns = array_shift($arguments) ?: [];

        return $this->descendants()->limitDepth($limit)->get($columns);
    }

    protected function getLevel(): int
    {
        if (is_null($this->getParentId())) {
            return 0;
        }

        return $this->ancestors()->count();
    }

    public function isDescendantOf(Model $other): bool
    {
        return $this->getPath() && str_starts_with($this->getPath(), $other->getPath() . $other->getKey() . '/');
    }

    public function isSelfOrDescendantOf(Model $other): bool
    {
        return $other->is($this) || $this->isDescendantOf($other);
    }

    public function isAncestorOf(Model $other): bool
    {
        return $other->isDescendantOf($this);
    }

    public function isSelfOrAncestorOf(Model $other): bool
    {
        return $other->is($this) || $this->isAncestorOf($other);
    }

    public function getPreviousSibling(): Model
    {
        return $this->siblings()->where($this->getOrderColumnName(), '<', $this->getOrder())
            ->orderBy($this->getOrderColumnName(), 'desc')->first();
    }

    public function getNextSibling(): Model
    {
        return $this->siblings()->where($this->getOrderColumnName(), '>', $this->getOrder())->first();
    }

    public function makeSiblingOf($node): self
    {
        return $this->makeNextSiblingOf($node);
    }

    public function makePreviousSiblingOf($node): self
    {
        return $this->moveTo($node, 'before');
    }

    public function makeNextSiblingOf($node): self
    {
        return $this->moveTo($node, 'after');
    }

    public function makeChildOf($node): self
    {
        return $this->moveTo($node, 'child');
    }

    public function makeRoot(): self
    {
        return $this->moveTo($this, 'root');
    }

    protected function setDepth(): self
    {
        $this->setAttribute($this->getDepthColumnName(), $this->getLevel());

        return $this;
    }

    protected function setPath(): self
    {
        $path = ($this->parent ? $this->parent->getPath() : '') . $this->getParentId() . '/';

        $this->setAttribute($this->getPathColumnName(), $path);

        return $this;
    }

    protected function moveTo($target, $position): self
    {
        //We wish to pass the old parent.
        $previousParent = $this->parent;

        switch ($position) {
            case 'root':
                $this->parent()->dissociate();
                if ($this->autoOrdering) {
                    $this->setAttribute($this->getOrderColumnName(), $this->getMaxOrder() + 1);
                }
                break;

            case 'child':
                if (!$target instanceof Model) {
                    $target = self::findOrFail($target);
                }
                if ($target->isSelfOrDescendantOf($this)) {
                    throw new MoveNotPossibleException('Cannot make unit descendant of itself');
                }
                $this->parent()->associate($target);
                break;

            case 'before':
                $this->parent()->associate($target->parent);
                if ($this->autoOrdering) {
                    $this->setAttribute($this->getOrderColumnName(), $target->getOrder());
                }
                break;

            case 'after':
                $this->parent()->associate($target->parent);
                if ($this->autoOrdering) {
                    $this->setAttribute($this->getOrderColumnName(), $target->getOrder() + 1);
                }
                break;
        }

        $this->save();

        if ($this->autoOrdering) {
            $this->updateOrdering($previousParent);
        }

        MaterializedModelMovedEvent::dispatch($this, $previousParent);

        return $this;
    }

    protected function getMaxOrder(): int
    {
        return (int)$this->newQuery()
            ->when(
                $this->parent,
                fn (Builder $query) => $query->where($this->getParentColumnName(), $this->parent->getKey()),
                fn (Builder $query) => $query->whereNull($this->getParentColumnName())
            )
            ->max($this->getOrderColumnName());
    }

    protected function updateOrdering(?Model $previousParent): void
    {
        $this->when(
                $this->parent,
                fn (Builder $query) => $query->where($this->getParentColumnName(), $this->getParentId()),
                fn (Builder $query) => $query->whereNull($this->getParentColumnName())
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
                fn (Builder $query) => $query->where($this->getParentColumnName(), $parent->getKey()),
                fn (Builder $query) => $query->whereNull($this->getParentColumnName())
            )->each(function (Model $node) use (&$ordering) {
                $node->update([$node->getOrderColumnName() => ++$ordering]);
            });
    }
}
