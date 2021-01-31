<?php
namespace Vicklr\MaterializedModel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class MaterializedModelServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Blueprint::macro(
            'materializedFields',
            function ($parent_name = 'parent_id', $path_name = 'path', $depth_name = 'depth', $primary_name = 'id') {
                $this->unsignedBigInteger($parent_name)->nullable();
                $this->string($path_name, 191)->nullable();
                $this->unsignedInteger($depth_name)->nullable();

                $this->foreign($parent_name)->references($primary_name)->on($this->getTable())->onDelete('CASCADE');
                $this->index($path_name);
            }
        );
        Blueprint::macro('materializedOrdering', function ($order_name = 'ordering', $numerical = true) {
            if ($numerical) {
                $this->unsignedInteger($order_name)->nullable();
            } else {
                $this->string($order_name, 191)->nullable();
            }
        });
    }
}
