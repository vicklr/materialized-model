<?php

namespace Vicklr\MaterializedModel\Test;

use Vicklr\MaterializedModel\MaterializedModel;

class Folder extends MaterializedModel
{
    protected $guarded = [];

    protected string $orderColumn = 'name';

    protected bool $autoOrdering = false;
}
