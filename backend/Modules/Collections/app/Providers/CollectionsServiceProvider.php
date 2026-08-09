<?php

namespace Modules\Collections\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\Collections\Console\Commands\BackfillCollectionSettingsCommand;
use Modules\Collections\Console\Commands\BackfillDelinquencySettingsCommand;
use Modules\Collections\Console\Commands\BackfillDirectChargeConceptsCommand;
use Modules\Collections\Console\Commands\GenerateMonthlyChargesCommand;
use Modules\Collections\Console\Commands\RefreshChargeStatusesCommand;
use Modules\Collections\Console\Commands\SendChargeRemindersCommand;
use Modules\Collections\Services\DelinquencyRestrictionService;
use Modules\Collections\Support\PaymentEarmarkRegistry;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class CollectionsServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Collections';

    protected string $nameLower = 'collections';

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

        // Earmarked payments seam: consuming modules (e.g. Projects) register their
        // handler class-strings here from their boot(). Octane-safe (class-strings only).
        $this->app->singleton(PaymentEarmarkRegistry::class);

        // scoped(), never singleton(): the settings() memoization inside the service
        // must span exactly one request. singleton() would pin one tenant's settings
        // across every Octane-reused request; the plain default binding would create a
        // new instance per *resolution*, re-reading settings at every hook call site.
        $this->app->scoped(DelinquencyRestrictionService::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            GenerateMonthlyChargesCommand::class,
            RefreshChargeStatusesCommand::class,
            SendChargeRemindersCommand::class,
            BackfillCollectionSettingsCommand::class,
            BackfillDirectChargeConceptsCommand::class,
            BackfillDelinquencySettingsCommand::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);
            // Daily status refresh (time-based transitions: pending→due_soon→overdue).
            $schedule->command('collections:refresh-statuses')->dailyAt('01:00');
            // Charge generation runs DAILY; the command self-filters per tenant, only
            // generating when today's day-of-month equals that tenant's configurable
            // collections.charge_generation_day (1-28).
            $schedule->command('collections:generate-monthly')->dailyAt('06:00');
            // Daily charge reminders (before due, on due day, every M days in arrears).
            $schedule->command('collections:send-reminders')->dailyAt('07:00');
        });
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
            $this->loadTranslationsFrom(module_path($this->name, '$PATH_LANG$'), $this->nameLower);
            $this->loadJsonTranslationsFrom(module_path($this->name, '$PATH_LANG$'));
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
        // 'resources/views' (NOT the unreplaced '$PATH_VIEWS$' stub placeholder) so the
        // 'collections::' view namespace actually resolves — same as ReportsServiceProvider.
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
