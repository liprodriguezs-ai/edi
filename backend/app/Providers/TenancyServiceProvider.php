<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Facades\Module;
use Spatie\Permission\PermissionRegistrar;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;

class TenancyServiceProvider extends ServiceProvider
{
    public function events(): array
    {
        return [
            Events\CreatingTenant::class => [],
            // Single-DB: a new tenant only needs its default rows seeded into the shared
            // database (tenant_id is auto-stamped by BelongsToTenant). No database is created.
            Events\TenantCreated::class => [
                function (Events\TenantCreated $event): void {
                    $tenant = $event->tenant;
                    DB::afterCommit(function () use ($tenant): void {
                        Artisan::call('tenants:seed', [
                            '--tenants' => [$tenant->getTenantKey()],
                            '--force' => true,
                        ]);
                    });
                },
            ],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            // Single-DB: no per-tenant database to drop. The tenant row is soft-deleted;
            // if hard cleanup of a tenant's rows is ever needed, do it with a scoped job.
            Events\TenantDeleted::class => [],

            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
                function (Events\TenancyEnded $event) {
                    $permissionRegistrar = app(PermissionRegistrar::class);
                    $permissionRegistrar->cacheKey = 'spatie.permission.cache';
                },
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [
                function (Events\TenancyBootstrapped $event) {
                    $permissionRegistrar = app(PermissionRegistrar::class);
                    $permissionRegistrar->cacheKey = 'spatie.permission.cache.tenant.'.$event->tenancy->tenant->getTenantKey();
                },
            ],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],
        ];
    }

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->bootEvents();
        $this->registerModuleMigrations();
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    /**
     * Single-database tenancy: ALL module migrations run on the one shared
     * connection via `php artisan migrate`. There is no per-tenant database and
     * no module.json "tenancy" routing — tenant tables are identified by their
     * tenant_id column (BaseModel::columns($table, true) + the BelongsToTenant trait).
     */
    protected function registerModuleMigrations(): void
    {
        foreach (Module::allEnabled() as $module) {
            $path = $module->getExtraPath('database/migrations');
            if (is_dir($path)) {
                $this->loadMigrationsFrom($path);
            }
        }
    }
}
