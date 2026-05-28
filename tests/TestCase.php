<?php

namespace Disintegrations\EitaaSerializer\Tests;

use Disintegrations\EitaaSerializer\EitaaSerializerServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            EitaaSerializerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('eitaa.schema_path', __DIR__.'/../resources/eitaa/schema.json');
    }
}
