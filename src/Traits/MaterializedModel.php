<?php

namespace Vicklr\MaterializedModel\Traits;

use Illuminate\Support\Collection;
use Vicklr\MaterializedModel\HierarchyCollection;
use Vicklr\MaterializedModel\Events\MaterializedModelMovedEvent;
use Vicklr\MaterializedModel\Exceptions\MoveNotPossibleException;
use Illuminate\Database\Eloquent\Model;

trait MaterializedModel
{
    /**
     * Column name to store the reference to parent's node.
     *
     * @var string
     */
    protected $parentColumn = 'parent_id';

    /**
     * Column name for path.
     *
     * @var string
     */
    protected $pathColumn = 'path';

    /**
     * Column name for depth field.
     *
     * @var string
     */
    protected $depthColumn = 'depth';

    /**
     * Column name for ordering field.
     *
     * @var string
     */
    protected $orderColumn = 'name';

    protected static function bootMaterializedModel()
    {
        static::saving(function (Model $node) {
            $node->setPath();
            $node->setDepth();
        });

        static::saved(function (Model $node) {
            if ($node->wasChanged('parent_id')) {
                $node->rebuild(false);
            }
        });
    }

    public function getParentColumnName()
    {
        return $this->parentColumn;
    }

    public function getQualifiedParentColumnName()
    {
        return $this->getTable() . '.' . $this->getParentColumnName();
    }

    public function getParentId()
    {
        return $this->getAttribute($this->getparentColumnName());
    }

    public function getPathColumnName()
    {
        return $this->pathColumn;
    }

    public function getQualifiedPathColumnName()
    {
        return $this->getTable() . '.' . $this->getPathColumnName();
    }

    public function getPath()
    {
        return $this->getAttribute($this->getPathColumnName());
    }

    public function getDepthColumnName()
    {
        return $this->depthColumn;
    }

    public function getQualifiedDepthColumnName()
    {
        return $this->getTable() . '.' . $this->getDepthColumnName();
    }

    public function getDepth()
    {
        return $this->getAttribute($this->getDepthColumnName());
    }

    public function getOrderColumnName()
    {
        return is_null($this->orderColumn) ? $this->getPathColumnName() : $this->orderColumn;
    }

    public function getQualifiedOrderColumnName()
    {
        return $this->getTable() . '.' . $this->getOrderColumnName();
    }

    public function getOrder()
    {
        return $this->getAttribute($this->getOrderColumnName());
    }

    public function parent()
    {
        return $this->belongsTo(get_class($this), $this->getParentColumnName())->withoutGlobalScopes();
    }

    public function children()
    {
        return $this->hasMany(get_class($this), $this->getParentColumnName())->withoutGlobalScopes()->orderBy($this->getOrderColumnName());
    }

    public function newMaterializedPathQuery()
    {
        return $this->newQuery()->orderBy($this->getQualifiedOrderColumnName());
    }

    public function newCollection(array $models = [])
    {
        return (new HierarchyCollection($models))->setClassName(__CLASS__);
    }

    public static function all($columns = ['*'])
    {
        $instance = new static;

        return $instance->newQuery()->orderBy($instance->getQualifiedOrderColumnName())->get($columns);
    }

    public static function root()
    {
        return static::roots()->first();
    }

    public static function roots()
    {
        $instance = new static;

        return $instance->newQuery()->whereNull($instance->getParentColumnName())
            ->orderBy($instance->getQualifiedOrderColumnName());
    }

    public function rebuild($save_self = true)
    {
        if ($save_self) {
            $this->save();
        }
        if ($this->children()->count()) {
            $this->children()->get()->each->rebuild();
        }
    }

    public function scopeWithoutNode($query, $node)
    {
        return $query->where($node->getKeyName(), '!=', $node->getKey());
    }

    public function scopeWithoutNodes($query, Collection $nodes)
    {
        return $query->whereNotIn($nodes->first()->getKeyName(), $nodes->pluck($nodes->first()->getKey()));
    }

    public function scopeWithoutSelf($query)
    {
        return $this->scopeWithoutNode($query, $this);
    }

    public function scopeLimitDepth($query, $limit)
    {
        $depth = $this->exists ? $this->getDepth() : $this->getLevel();
        $max = $depth + $limit;
        $scopes = [$depth, $max];

        return $query->whereBetween($this->getDepthColumnName(), [min($scopes), max($scopes)]);
    }

    public function isRoot()
    {
        return is_null($this->getParentId());
    }

    public function isChild()
    {
        return !$this->isRoot();
    }

    public function getRoot()
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

    public function ancestorsAndSelf()
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

    public function getAncestorsAndSelf($columns = ['*'])
    {
        return $this->ancestorsAndSelf()->get($columns);
    }

    public function ancestors()
    {
        return $this->ancestorsAndSelf()->withoutSelf();
    }

    public function getAncestors($columns = ['*'])
    {
        return $this->ancestors()->get($columns);
    }

    public function siblingsAndSelf()
    {
        return $this->newMaterializedPathQuery()->where($this->getParentColumnName(), $this->getParentId());
    }

    public function getSiblingsAndSelf($columns = ['*'])
    {
        return $this->siblingsAndSelf()->get($columns);
    }

    public function siblings()
    {
        return $this->siblingsAndSelf()->withoutSelf();
    }

    public function getSiblings($columns = ['*'])
    {
        return $this->siblings()->get($columns);
    }

    public function descendants($include_self = false)
    {
        return $this->newMaterializedPathQuery()
            ->where(function ($query) use ($include_self) {
                $query->where($this->getPathColumnName(), 'like', $this->getPath() . $this->getKey() . '/%');
                $query->when($include_self, function ($query) {
                    $query->orWhere($this->getKeyName(), $this->getKey());
                });
            });
    }

    public function descendantsAndSelf()
    {
        return $this->descendants(true);
    }

    public function getDescendantsAndSelf($columns = ['*'])
    {
        if (is_array($columns)) {
            return $this->descendantsAndSelf()->get($columns);
        }

        $arguments = func_get_args();

        $limit = intval(array_shift($arguments));
        $columns = array_shift($arguments) ?: ['*'];

        return $this->descendantsAndSelf()->limitDepth($limit)->get($columns);
    }

    public function getDescendants($columns = ['*'])
    {
        if (is_array($columns)) {
            return $this->descendants()->get($columns);
        }

        $arguments = func_get_args();

        $limit = intval(array_shift($arguments));
        $columns = array_shift($arguments) ?: ['*'];

        return $this->descendants()->limitDepth($limit)->get($columns);
    }

    public function getLevel()
    {
        if (is_null($this->getParentId())) {
            return 0;
        }

        return $this->ancestors()->count();
    }

    public function isDescendantOf(Model $other)
    {
        return $this->getPath() && strpos($this->getPath(), $other->getPath() . $other->getKey() . '/') === 0;
    }

    public function isSelfOrDescendantOf(Model $other)
    {
        return $other->is($this) || $this->isDescendantOf($other);
    }

    public function isAncestorOf(Model $other)
    {
        return $other->isDescendantOf($this);
    }

    public function isSelfOrAncestorOf(Model $other)
    {
        return $other->is($this) || $this->isAncestorOf($other);
    }

    public function getLeftSibling()
    {
        return $this->siblings()->where($this->getOrderColumnName(), '<', $this->getOrder())
            ->orderBy($this->getOrderColumnName(), 'desc')->first();
    }

    public function getRightSibling()
    {
        return $this->siblings()->where($this->getOrderColumnName(), '>', $this->getOrder())->first();
    }

    public function makeSiblingOf($node)
    {
        return $this->makeChildOf($node->parent);
    }

    public function makeChildOf($node)
    {
        return $this->moveTo($node, 'child');
    }

    public function makeRoot()
    {
        return $this->moveTo($this, 'root');
    }

    public function setDepth()
    {
        $level = $this->getLevel();

        $this->newMaterializedPathQuery()->where($this->getKeyName(), '=', $this->getKey())
            ->update([$this->getDepthColumnName() => $level]);
        $this->setAttribute($this->getDepthColumnName(), $level);

        return $this;
    }

    public function setPath()
    {
        $path = ($this->parent ? $this->parent->getPath() : '') . $this->getParentId() . '/';

        $this->newMaterializedPathQuery()->where($this->getKeyName(), '=', $this->getKey())
            ->update([$this->getPathColumnName() => $path]);
        $this->setAttribute($this->getPathColumnName(), $path);

        return $this;
    }

    protected function moveTo($target, $position)
    {
        //We wish to pass the old parent.
        $previousParent = $this->parent;

        switch ($position) {
            case 'root':
                $this->parent()->dissociate();
                break;

            case 'child':
                if (!$target instanceof Model) {
                    $target = self::findOrFail($target);
                }
                if ($target->isSelfOrDescendantOf($this)) {
                    throw new MoveNotPossibleException('Cannot make unit child of a child');
                }
                $this->parent()->associate($target);
                break;
        }

        $this->rebuild();

        MaterializedModelMovedEvent::dispatch($this, $previousParent);

        return $this;
    }
}
