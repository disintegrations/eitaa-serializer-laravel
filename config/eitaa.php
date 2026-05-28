<?php

return [
    'endpoint' => env('EITAA_GATEWAY_ENDPOINT', 'https://sajad.eitaa.ir/eitaa/'),
    'layer' => (int) env('EITAA_LAYER', 133),
    'default_imei' => env('EITAA_DEFAULT_IMEI', '00__web'),
    'timeout' => (int) env('EITAA_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | TL schema path
    |--------------------------------------------------------------------------
    |
    | Leave this null to use the schema bundled with the package. Set it only
    | when you publish/customize resources/eitaa/schema.json in your app.
    |
    */
    'schema_path' => env('EITAA_SCHEMA_PATH'),
];

