<?php

namespace Disintegrations\EitaaSerializer;

use Illuminate\Support\ServiceProvider;
use Disintegrations\EitaaSerializer\TL\TlSchema;

class EitaaSerializerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eitaa.php', 'eitaa');

        $this->app->singleton(TlSchema::class, function (): TlSchema {
            return TlSchema::fromFile($this->schemaPath());
        });

        $this->app->singleton(EitaaGatewayClient::class, function ($app): EitaaGatewayClient {
            return new EitaaGatewayClient($app->make(TlSchema::class));
        });

        $this->app->alias(EitaaGatewayClient::class, 'eitaa');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/eitaa.php' => config_path('eitaa.php'),
        ], 'eitaa-config');

        $this->publishes([
            __DIR__.'/../resources/eitaa/schema.json' => resource_path('eitaa/schema.json'),
        ], 'eitaa-schema');
    }

    private function schemaPath(): string
    {
        $configuredPath = config('eitaa.schema_path');

        return is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : __DIR__.'/../resources/eitaa/schema.json';
    }
}

