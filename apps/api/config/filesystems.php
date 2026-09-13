<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        /*
         * Product photographs, and the endpoint the comment on `local` below
         * promised: they are served by a route under api/v1 that streams them,
         * not by a file server switched on with a config flag (ADR 0016).
         *
         * Private, therefore. Nothing outside this application can read the
         * directory, and an image is reachable only by its unguessable key.
         *
         * A separate disk rather than a folder inside `local` so that pointing
         * product images at object storage later is one line here, and every
         * row already records which disk it was written to.
         */
        'products' => [
            'driver' => 'local',
            'root' => storage_path('app/products'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        /*
         * The same photographs, in a bucket (ADR 0048).
         *
         * A second disk rather than a changed one, because `product_images.disk`
         * is recorded per row and a marketplace that moves to object storage
         * still has to serve everything uploaded before the move. Switching
         * `PRODUCT_IMAGE_DISK` moves *new* uploads; every old row keeps
         * answering from `products` above (ADR 0016).
         *
         * **No credentials here.** The SDK uses Application Default
         * Credentials, which on Cloud Run is the service account the revision
         * runs as - so there is no key to put in an environment variable, no
         * secret to rotate, and nothing to leak.
         *
         * `api_endpoint` is empty in production and points at fake-gcs-server
         * in development. It is this application's variable rather than the
         * SDK's: the PHP client has no `STORAGE_EMULATOR_HOST` support, unlike
         * the Go and Python ones (ADR 0048).
         *
         * The bucket stays entirely private. Images are streamed by a route
         * under api/v1 that checks the signature itself (ADR 0016), so nothing
         * here is public and no object URL is ever handed out.
         */
        'products_bucket' => [
            'driver' => 'gcs',
            'bucket' => env('GCS_BUCKET'),
            'project_id' => env('GCS_PROJECT_ID'),
            'api_endpoint' => env('GCS_API_ENDPOINT'),

            // Throwing, like `products`: a write that silently failed would be
            // a listing with a photograph that is not there.
            'throw' => true,
            'report' => false,
        ],

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),

            // Off, because `true` registers GET|PUT /storage/{path} - a route
            // outside api/v1 that nothing proxies to and nothing calls.
            // Nothing is stored here yet. When product images arrive they get
            // an endpoint with an authorization check on it, not a file server
            // enabled by a config default.
            'serve' => false,

            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            // Cast because env() returns a bool for a value of "true" or
            // "false", and an APP_URL of either would otherwise reach rtrim()
            // as a bool and be silently coerced to "1" or "".
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
