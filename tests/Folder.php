<?php

namespace Vicklr\MaterializedModel\Test;

use Illuminate\Database\Eloquent\Model;
use Vicklr\MaterializedModel\Traits\MaterializedModel;

class Folder extends Model
{
    use MaterializedModel;

    protected $guarded = [];

    protected $table = 'folders';

}
