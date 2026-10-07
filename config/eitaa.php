<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Gateway Endpoint
    |--------------------------------------------------------------------------
    |
    | Ordinary send() and sendPackedData() calls use this endpoint, including
    | text messages and history requests. Upload calls use upload_endpoint.
    |
    */
    'endpoint' => env('EITAA_GATEWAY_ENDPOINT', 'https://sajad.eitaa.ir/eitaa/'),

    /*
    |--------------------------------------------------------------------------
    | Gateway Layer
    |--------------------------------------------------------------------------
    |
    | This is the protocol layer for ordinary requests. Layer 133 preserves
    | existing text behavior. Upload requests use the separate upload_layer.
    |
    */
    'layer' => (int) env('EITAA_LAYER', 133),

    /*
    |--------------------------------------------------------------------------
    | Gateway Envelope Flags
    |--------------------------------------------------------------------------
    |
    | Ordinary requests serialize this integer once after the layer field in
    | the eitaaObject envelope. These are separate from method parameter flags.
    |
    */
    'envelope_flags' => (int) env('EITAA_ENVELOPE_FLAGS', 0),

    /*
    |--------------------------------------------------------------------------
    | Legacy Gateway Envelope
    |--------------------------------------------------------------------------
    |
    | Enable this to omit the final envelope flags field on ordinary requests
    | for compatibility with the old four-field format. Upload requests always
    | include their envelope flags, regardless of this setting.
    |
    */
    'legacy_envelope' => (bool) env('EITAA_LEGACY_ENVELOPE', false),

    /*
    |--------------------------------------------------------------------------
    | Upload Endpoint
    |--------------------------------------------------------------------------
    |
    | sendUpload() and sendFile() use this endpoint. The file helper pins it
    | for every upload part and the final media send, without automatic failover.
    | Low-level multipart callers should use forUpload() to pin their route.
    |
    */
    'upload_endpoint' => env('EITAA_UPLOAD_ENDPOINT', 'https://alzheimer.eitaa.com/eitaa/'),

    /*
    |--------------------------------------------------------------------------
    | Upload Layer
    |--------------------------------------------------------------------------
    |
    | This protocol layer applies to upload requests and operations referencing
    | freshly uploaded media. Layer 135 is part of the verified media profile.
    |
    */
    'upload_layer' => (int) env('EITAA_UPLOAD_LAYER', 135),

    /*
    |--------------------------------------------------------------------------
    | Upload Envelope Flags
    |--------------------------------------------------------------------------
    |
    | Upload requests serialize this integer once as the final envelope field.
    | The official web media profile uses 128. Do not append flags manually.
    |
    */
    'upload_envelope_flags' => (int) env('EITAA_UPLOAD_ENVELOPE_FLAGS', 128),

    /*
    |--------------------------------------------------------------------------
    | Default IMEI
    |--------------------------------------------------------------------------
    |
    | This identifier is used when a call does not supply an IMEI. Authenticated
    | calls should provide the IMEI associated with their exported session.
    |
    */
    'default_imei' => env('EITAA_DEFAULT_IMEI', '00__web'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | This is the HTTP timeout in seconds for each gateway request, including
    | individual upload parts. It does not limit the entire upload workflow.
    |
    */
    'timeout' => (int) env('EITAA_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | TL Schema Path
    |--------------------------------------------------------------------------
    |
    | Leave this null to use the schema bundled with the package. Set it only
    | when you publish/customize resources/eitaa/schema.json in your app, using
    | an absolute file path. Custom schemas must include the response layouts
    | needed by your selected protocol layers, including modern media responses.
    |
    */
    'schema_path' => env('EITAA_SCHEMA_PATH'),
];
