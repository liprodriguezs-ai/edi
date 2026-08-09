<?php

return [
    'cache-prefix' => '',

    /*
     * Enable or disable model caching globally.
     * Defaults to false so the feature is opt-in: enable per environment by
     * setting MODEL_CACHE_ENABLED=true in .env (requires a taggable Redis store).
     */
    'enabled' => env('MODEL_CACHE_ENABLED', false),

    /*
     * Database-keying prefixes cache keys with the connection name + database
     * name. This project uses a SINGLE shared database (single-DB tenancy),
     * so database-keying adds no value and is disabled by default.
     */
    'use-database-keying' => env('MODEL_CACHE_USE_DATABASE_KEYING', false),

    /*
     * The cache store to use. genealabs/laravel-model-caching requires a
     * taggable store. The 'redis' store defined in config/cache.php satisfies
     * that requirement. Array/file/database are NOT taggable and will silently
     * make the Cachable trait a no-op (false greens in tests).
     */
    'store' => env('MODEL_CACHE_STORE', 'redis'),
];
