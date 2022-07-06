<?php

namespace Vicklr\MaterializedModel\Test\Model;

use Vicklr\MaterializedModel\MaterializedModel;

class Folder extends MaterializedModel
{
    protected $guarded = [];

    protected string $orderColumn = 'name';
}
