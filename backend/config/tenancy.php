<?php

declare(strict_types=1);
use Modules\Tenant\Database\Seeders\TenantDatabaseSeeder;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\UUIDGenerator;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => UUIDGenerator::class,

    'domain_model' => Domain::class,

    'central_domains' => [
        '127.0.0.1',
        'localhost',
    ],

    /*
     * QueueTenancyBootstrapper: serializa el tenant_id en el payload al despachar
     * un job e inicializa la tenancy correspondiente antes de ejecutar handle().
     * Por eso los jobs pueden vivir en una tabla "jobs" central y aun así correr
     * en contexto del tenant que los originó.
     */
    'bootstrappers' => array_values(array_filter([
        // Single-database tenancy: NO DatabaseTenancyBootstrapper (no per-tenant DB switching).
        // Isolation is by tenant_id column + TenantScope (BelongsToTenant trait).
        // El bootstrapper de cache usa tags — el driver "file" no los soporta, así
        // que en dev local con file se omite (redis/array/memcached sí lo conservan).
        env('CACHE_STORE', env('CACHE_DRIVER', 'file')) === 'file' ? null : CacheTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ])),

    'cache' => [
        'tag_base' => 'tenant',
    ],

    'filesystem' => [
        'suffix_base' => 'tenant',
        'disks' => [
            'local',
            'public',
        ],

        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],

        'suffix_storage_path' => true,
        'asset_helper_tenancy' => true,
    ],

    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],

    'features' => [],

    'routes' => false,

    /**
     * Migration paths: TenancyServiceProvider loads every enabled module's
     * migrations into the normal `php artisan migrate` run. There is no
     * module.json 'tenancy' flag anymore (removed); tenant-scoping is
     * expressed by BaseModel::columns($table, true) + the BelongsToTenant trait.
     */
    'migration_parameters' => [
        '--force' => true,
        '--path' => [],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => TenantDatabaseSeeder::class,
    ],
];
