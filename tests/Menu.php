<?php

namespace Vicklr\MaterializedModel\Test;

use Illuminate\Database\Eloquent\Model;
use Vicklr\MaterializedModel\Traits\HasMaterializedPaths;

class Menu extends Model
{
    use HasMaterializedPaths;

    protected $guarded = [];
}
