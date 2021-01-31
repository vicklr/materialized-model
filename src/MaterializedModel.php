<?php
namespace Vicklr\MaterializedModel;

use Illuminate\Database\Eloquent\Model;
use Vicklr\MaterializedModel\Traits\HasMaterializedPaths;

class MaterializedModel extends Model
{
    use HasMaterializedPaths;
}
