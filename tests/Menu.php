<?php

namespace Vicklr\MaterializedModel\Test;

use Vicklr\MaterializedModel\MaterializedModel;

class Menu extends MaterializedModel
{
    protected $guarded = [];

    protected bool $autoOrdering = true;
}
