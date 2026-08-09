<?php

namespace Modules\SubscriptionPlan\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\SubscriptionPlan\Console\Commands\ExpireManualEntitlementsCommand;
use Modules\SubscriptionPlan\Console\Commands\RecomputeEntitlementsCommand;
use Modules\SubscriptionPlan\Console\Commands\SyncBillingStripeCommand;
use Modules\SubscriptionPlan\Console\Commands\VerifyBillingCatalogCommand;
use Modules\SubscriptionPlan\Contracts\QuotaSubjectCounter;
use Modules\SubscriptionPlan\Services\NullQuotaSubjectCounter;
use Modules\SubscriptionPlan\Services\QuotaService;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SubscriptionPlanServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'SubscriptionPlan';

    protected string $nameLower = 'subscriptionplan';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);

        // scoped(), never singleton(): one instance per request — QuotaService
        // resolves tenant-scoped state (plan_quota/tenant_quota_usage) that must
        // never pin one tenant's data across Octane-reused requests.
        $this->app->scoped(QuotaService::class);

        // WHAT the billing band counts is a domain decision. EDI has no billed
        // subject yet, so the null counter keeps every band check fail-open
        // while the rest of the billing machinery stays wired and tested.
        // Swap this binding — never UnitLimitService — once the subject exists.
        $this->app->bind(QuotaSubjectCounter::class, NullQuotaSubjectCounter::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            SyncBillingStripeCommand::class,
            ExpireManualEntitlementsCommand::class,
            RecomputeEntitlementsCommand::class,
            VerifyBillingCatalogCommand::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        // $this->app->booted(function () {
        //     $schedule = $this->app->make(Schedule::class);
        //     $schedule->command('inspire')->hourly();
        // });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->nameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->nameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
            $this->loadJsonTranslationsFrom(module_path($this->name, 'lang'));
        }
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $relativeConfigPath = config('modules.paths.generator.config.path');
        $configPath = module_path($this->name, $relativeConfigPath);

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relativePath = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $configKey = $this->nameLower.'.'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $relativePath);
                    $key = ($relativePath === 'config.php') ? $this->nameLower : $configKey;

                    $this->publishes([$file->getPathname() => config_path($relativePath)], 'config');
                    $this->mergeConfigFrom($file->getPathname(), $key);
                }
            }
        }
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

        $componentNamespace = $this->module_namespace($this->name, $this->app_path(config('modules.paths.generator.component-class.path')));
        Blade::componentNamespace($componentNamespace, $this->nameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->nameLower)) {
                $paths[] = $path.'/modules/'.$this->nameLower;
            }
        }

        return $paths;
    }
}
