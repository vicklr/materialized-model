<?php

namespace Vicklr\MaterializedModel\Test;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Vicklr\MaterializedModel\MaterializedModelServiceProvider;

abstract class TestCase extends Orchestra
{
    public function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase($this->app);
    }

    protected function getPackageProviders($app)
    {
        return [
            MaterializedModelServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.prefix', 'vicklr_tests---');
    }

    protected function setUpDatabase(Application $app)
    {
        $app['db']->connection()->getSchemaBuilder()->create('folders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->materializedFields();
            $table->timestamps();
        });

        $app['db']->connection()->getSchemaBuilder()->create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->materializedFields();
            $table->materializedOrdering();
            $table->timestamps();
        });
    }
}
