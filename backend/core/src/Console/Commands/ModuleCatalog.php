<?php

namespace QCH\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ModuleCatalog extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'make:catalog {name} {--title-singular=} {--title-plural=} {--values=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create module';

    protected $modulesPath = '';

    protected $stubsPath = '';

    protected $params = [];

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->modulesPath = base_path('app/Modules/');
        $this->modulesPathFront = base_path('Modules/');
        $this->stubsPath = dirname(__FILE__).'/../../Stubs/';
        $values = $this->option('values');
        $module = $this->argument('name');
        $moduleCamel = Str::camel($module);
        $moduleSnake = Str::snake($moduleCamel);
        $moduleKebab = str_replace('_', '-', $moduleSnake);
        $moduleStudly = Str::studly($module);
        $moduleTitle = Str::title($titleSingular ?? str_replace('_', ' ', $moduleSnake));
        $moduleTitlePlural = Str::title($titlePlural ?? str_replace('_', ' ', $moduleSnake));
        $moduleSnakeLower = strtolower($moduleSnake);

        $this->call('module:make-migration', [
            'name' => "create_{$moduleSnakeLower}_table",
            'module' => 'Catalog',
            '--fields' => 'name:string',
        ]);

        /*$this->call('module:migrate', [
            'module' => "Catalog",
        ]);*/

        $this->params = [
            '$MODULE_CAMEL$' => $moduleCamel,
            '$MODULE_TITLE$' => $moduleTitle,
            '$MODULE_TITLE_PLURAL$' => $moduleTitlePlural,
            '$MODULE_SNAKE$' => $moduleSnake,
            '$MODULE_STUDLY$' => $moduleStudly,
            '$MODULE_KEBAB$' => $moduleKebab,
            '$MODULE$' => $module,
            '$TABLE$' => empty($table) ? $module : $table,
        ];

        $this->replaceParam(
            $this->stubsPath.'Core/catalog.phtml',
            base_path("Modules/Catalog/Entities/{$moduleStudly}.php")
        );

        $this->replaceParam(
            dirname(__FILE__).'/../../Commands/stubs/seeder-catalog.stub',
            base_path("Modules/Catalog/Database/Seeders/{$moduleStudly}TableSeeder.php")
        );

        $catalogSeeder = base_path('Modules/Catalog/Database/Seeders/CatalogDatabaseSeeder.php');

        $strFile = file_get_contents($catalogSeeder);
        $strFile = str_replace('//REPLACE', "//REPLACE\t\t\$this->call({$moduleStudly}TableSeeder::class);",
            $strFile);
        file_put_contents($catalogSeeder, $strFile);

        $fileCsv = base_path("Modules/Catalog/Database/Seeders/csv/{$moduleSnake}.csv");

        $this->replaceParam(
            dirname(__FILE__).'/../../Commands/stubs/csv.stub',
            $fileCsv
        );

        $values = $this->option('values');

        if (! empty($values)) {
            $values = explode(',', $values);
            $fp = fopen($fileCsv, 'a');
            foreach ($values as $value) {
                fputcsv($fp, [$value]);
            }
            fclose($fp);

            $this->call('module:seed', [
                'module' => 'Catalog',
                '--class' => "{$moduleStudly}TableSeeder",
            ]);
        }

        $this->info("Starting create module : {$module}");

        $moduleExist = $this->checkRegistermodule($module);
        $indexModule = $this->countModuleInSeed();
        if (! $moduleExist) {
            $permissions = [
                ["Lectura {$moduleTitle}", "read.{$moduleCamel}", '', '', $indexModule],
                ["Creación {$moduleTitle}", "create.{$moduleCamel}", '', '', $indexModule],
                ["Edicion {$moduleTitle}", "update.{$moduleCamel}", '', '', $indexModule],
                ["Inactivar {$moduleTitle}", "delete.{$moduleCamel}", '', '', $indexModule],
                ["Activar {$moduleTitle}", "restore.{$moduleCamel}", '', '', $indexModule],
                ["Reporte {$moduleTitle}", "report.{$moduleCamel}", '', '', $indexModule],
            ];
            $this->setModule2Seed($moduleTitle);
            $this->setPermission2Seed($permissions);
        }

        $this->info("Lectura {$moduleTitle},read.{$moduleCamel},,");
        $this->info("Creación {$moduleTitle},create.{$moduleCamel},,");
        $this->info("Edicion {$moduleTitle},update.{$moduleCamel},,");
        $this->info("Inactivar {$moduleTitle},delete.{$moduleCamel},,");
        $this->info("Activar {$moduleTitle},restore.{$moduleCamel},,");
        $this->info("Reporte {$moduleTitle},report.{$moduleCamel},,");

        return false;
    }

    protected function countModuleInSeed()
    {
        // header no count
        $row = 0;
        if (($file = fopen(base_path('database/seeders/csv/modules.csv'), 'r')) !== false) {
            while (($data = fgetcsv($file, 1000, ',')) !== false) {
                $row++;
            }
            fclose($file);
        }

        return $row;
    }

    protected function setModule2Seed($module)
    {
        $fp = fopen(base_path('database/seeders/csv/modules.csv'), 'a');
        fputcsv($fp, [$module]);
        fclose($fp);
    }

    protected function setPermission2Seed($permissions)
    {
        $fp = fopen(base_path('database/seeders/csv/permissions.csv'), 'a');
        foreach ($permissions as $permission) {
            fputcsv($fp, $permission);
        }
        fclose($fp);
    }

    protected function checkRegistermodule($module)
    {
        $row = 0;
        if (($file = fopen(base_path('database/seeders/csv/modules.csv'), 'r')) !== false) {
            while (($data = fgetcsv($file, 1000, ',')) !== false) {
                if ($data[0] == $module) {
                    return array_merge(
                        ['id' => $row],
                        $data
                    );
                }
                $row++;
            }
            fclose($file);
        }

        return false;
    }

    protected function includeScript($file, $toFile, $section)
    {
        $strFile = file_get_contents($file);

        foreach ($this->params as $param => $value) {
            $strFile = str_replace($param, $value, $strFile);
        }
        $toContentFile = file_get_contents($toFile);
        $toContentFile = str_replace("{$strFile}", '', $toContentFile);
        $toContentFile = str_replace($section, "{$section}{$strFile}", $toContentFile);
        file_put_contents($toFile, $toContentFile);
    }

    protected function replaceParam($file, $toFile)
    {
        $strFile = file_get_contents($file);
        foreach ($this->params as $param => $value) {
            $strFile = str_replace($param, $value, $strFile);
        }
        $toFile = str_replace('.phtml', '.php', $toFile);
        file_put_contents($toFile, $strFile);
    }

    protected function excludeFileAlias($file)
    {
        if (in_array($file, $this->excludeFile)) {
            return true;
        }

        return false;
    }

    protected function delTree($path)
    {
        if (is_dir($path) == true) {
            $rootFolder = scandir($path);
            if (count($rootFolder) > 2) {
                foreach ($rootFolder as $folder) {
                    if ($folder != '.' && $folder != '..') {
                        $this->delTree($path.'/'.$folder);
                    }
                }
                rmdir($path);
            }
        } else {
            if (file_exists($path) == true) {
                unlink($path);
            }
        }
    }

    protected function copyToDir($src, $dst, $alias = '', $replace = null, $aliasOnlyFile = null)
    {
        $dir = opendir($src);
        $result = $dir;
        if ($result === false) {
            return $result;
        }

        $result = @mkdir($dst);
        if (true) {
            while (false !== ($file = readdir($dir))) {
                if (! (($file != '.') && ($file != '..') && true)) {
                    continue;
                }
                if (is_dir($src.'/'.$file)) {
                    if ($aliasOnlyFile) {
                        $dirName = $file;
                    } else {
                        $dirName = $alias.$file;
                    }
                    $result = $this->copyToDir(
                        $src.'/'.$file,
                        $dst.'/'.$dirName,
                        $alias,
                        $replace,
                        $aliasOnlyFile
                    );
                } else {
                    $fileName = $alias.$file;
                    if ($this->excludeFileAlias($file)) {
                        $fileName = $file;
                    }
                    if ($replace) {
                        $this->replaceParam(
                            $src.'/'.$file,
                            $dst.'/'.$fileName
                        );
                    } else {
                        $result = copy($src.'/'.$file, $dst.'/'.$alias.$file);
                    }
                }
            }
            closedir($dir);
        }

        return $result;
    }
}
