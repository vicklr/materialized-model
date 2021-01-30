<?php

namespace Vicklr\MaterializedModel\Test;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase($this->app);
    }

    protected function getPackageProviders($app)
    {
        return [];
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
            $table->id('id');
            $table->string('path', 191)->default('/');
            $table->integer('depth')->default(0);
            $table->string('name', 191);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
            $table->foreign('parent_id')->references('id')->on('folders')->onDelete('CASCADE');
        });
    }
}