<?php

namespace Vicklr\MaterializedModel\Test\Model;

use Illuminate\Database\Eloquent\Model;
use Vicklr\MaterializedModel\Traits\HasOrderedMaterializedPaths;

class Menu extends Model
{
    use HasOrderedMaterializedPaths;

    protected $guarded = [];
}
