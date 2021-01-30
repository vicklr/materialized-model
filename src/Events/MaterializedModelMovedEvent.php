<?php

namespace Vicklr\MaterializedModel\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class MaterializedModelMovedEvent
{
    use Dispatchable;

    public Model $model;
    public ?Model $previousParent;

    public function __construct(Model $model, Model $previousParent = null)
    {
        $this->model = $model;
        $this->previousParent = $previousParent;
    }
}
