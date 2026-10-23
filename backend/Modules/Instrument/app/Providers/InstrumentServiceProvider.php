<?php

namespace Modules\Instrument\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\Instrument\Console\InstrumentVerifyCommand;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\AreaScoringRule;
use Modules\Instrument\Models\GlobalResultMessage;
use Modules\Instrument\Models\GlobalScoringRule;
use Modules\Instrument\Models\HeadCircumferenceReference;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\RiskFactor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;
use Modules\Instrument\Observers\InstrumentContentObserver;
use Modules\Instrument\Observers\InstrumentVersionObserver;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class InstrumentServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Instrument';

    protected string $nameLower = 'instrument';

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
        $this->registerObservers();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            InstrumentVerifyCommand::class,
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
     * Register the immutability observers (design D5): InstrumentContentObserver
     * guards every versioned content model, InstrumentVersionObserver guards the
     * registry row itself (only is_default may change after publish).
     */
    protected function registerObservers(): void
    {
        AgeGroup::observe(InstrumentContentObserver::class);
        ScreeningItem::observe(InstrumentContentObserver::class);
        ScreeningItemOption::observe(InstrumentContentObserver::class);
        RiskFactor::observe(InstrumentContentObserver::class);
        HeadCircumferenceReference::observe(InstrumentContentObserver::class);
        AreaScoringRule::observe(InstrumentContentObserver::class);
        GlobalScoringRule::observe(InstrumentContentObserver::class);
        GlobalResultMessage::observe(InstrumentContentObserver::class);

        InstrumentVersion::observe(InstrumentVersionObserver::class);
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
