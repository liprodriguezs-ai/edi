<?php

namespace QCH\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class Module extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'make:module {name} {--table=} {--title-singular=} {--title-plural=} {--clean=false} {--data=} {--catalog=} ';

    protected $excludeFile = ['routes.phtml', 'lang.phtml', 'config.phtml', 'start.php', '.gitkeep'];

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
        set_time_limit(0);
        $this->modulesPath = base_path('Modules/');
        $this->modulesPathFront = base_path('Modules/');
        $this->stubsPath = dirname(__FILE__).'/../../Stubs/';
        $module = $this->argument('name');
        $moduleCamel = Str::camel($module);
        $moduleSnake = Str::snake($moduleCamel);
        $moduleKebab = str_replace('_', '-', $moduleSnake);
        $moduleStudly = Str::studly($module);
        $moduleTitle = Str::title($titleSingular ?? str_replace('_', ' ', $moduleSnake));
        $moduleTitlePlural = Str::title($titlePlural ?? str_replace('_', ' ', $moduleSnake));
        $moduleSnakeLower = strtolower($moduleSnake);

        $this->call('module:make', [
            'name' => [$module],
        ]);
        $this->info("Create migration {$moduleSnakeLower}_table");
        $this->call('module:make-migration', [
            'name' => "create_{$moduleSnakeLower}_table",
            'module' => $module,
        ]);

        $this->info("Create model {$module}");
        $this->call('module:make-model', [
            'model' => $module,
            'module' => $module,
        ]);

        $this->info("Create service {$module}");
        $this->call('module:make-service', [
            'name' => "{$module}Service",
            'module' => $module,
        ]);

        $this->info("Create repository {$module}Repository");
        $this->call('module:make-repository', [
            'name' => "{$module}Repository",
            'module' => $module,
        ]);

        $this->info("Create request {$module}Request");
        $this->call('module:make-request', [
            'name' => "{$module}CreateRequest",
            'module' => $module,
        ]);

        $this->info("Create request {$module}Request");
        $this->call('module:make-request', [
            'name' => "{$module}UpdateRequest",
            'module' => $module,
        ]);

        // iterate all files and subfolder  in folder and replace $STUDLY_NAME$ with $moduleStudly
        $files = glob("Modules/{$module}/app/**/*.php", GLOB_BRACE);
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->info("Checking {$file}");
            // check if $STUDLY_NAME$ is in file

            $content = str_replace('$STUDLY_NAME$', $moduleStudly, $content);
            $content = str_replace('$SNAKE_NAME$', $moduleSnake, $content);
            $this->info("Replace \$STUDLY_NAME\$ with $moduleStudly in {$file}");
            file_put_contents($file, $content);
        }

        /*     $this->params = [
                 '$MODULE_CAMEL$' => $moduleCamel,
                 '$MODULE_TITLE$' => $moduleTitle,
                 '$MODULE_TITLE_PLURAL$' => $moduleTitlePlural,
                 '$MODULE_SNAKE$' => $moduleSnake,
                 '$MODULE_STUDLY$' => $moduleStudly,
                 '$STUDLY_NAME$' => $moduleStudly,
                 '$MODULE_KEBAB$' => $moduleKebab,
                 '$MODULE$' => $module,
                 '$TABLE$' => empty($table) ? $module : $table,
                 '$SNAKE_NAME$' => $moduleSnake,
                 '$KEBAB_NAME$' => $moduleKebab,
                 '$CAMEL_NAME$' => $moduleCamel,
                 '$LOWER_NAME$' => strtolower($module),
             ];*/

        /*$moduleExist = $this->checkRegistermodule($module);
        if (! $moduleExist) {

        }*/

        $id = Str::uuid7();
        $permissions = [
            [null, "Lectura {$moduleTitle}", "read.{$moduleCamel}", 'api', $id],
            [null, "Creación {$moduleTitle}", "create.{$moduleCamel}", 'api', $id],
            [null, "Edicion {$moduleTitle}", "update.{$moduleCamel}", 'api', $id],
            [null, "Inactivar {$moduleTitle}", "delete.{$moduleCamel}", 'api', $id],
            [null, "Activar {$moduleTitle}", "restore.{$moduleCamel}", 'api', $id],
            [null, "Reporte {$moduleTitle}", "report.{$moduleCamel}", 'api', $id],
        ];
        $this->setModule2Seed($id, $moduleTitle);
        $this->setPermission2Seed($permissions);

        $this->info("Lectura {$moduleTitle},read.{$moduleCamel},,");
        $this->info("Creación {$moduleTitle},create.{$moduleCamel},,");
        $this->info("Edicion {$moduleTitle},update.{$moduleCamel},,");
        $this->info("Inactivar {$moduleTitle},delete.{$moduleCamel},,");
        $this->info("Activar {$moduleTitle},restore.{$moduleCamel},,");
        $this->info("Reporte {$moduleTitle},report.{$moduleCamel},,");

        $this->replaceNameFiles($module);
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

    protected function setModule2Seed($id, $module)
    {
        $fp = fopen(base_path('database/seeders/csv/modules.csv'), 'a');
        fputcsv($fp, [$id, $module, 'Configuración']);
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

    protected function replaceNameFiles($module)
    {
        $files = config('modules.stubs.files');
        foreach ($files as $file) {
            $strFile = $file;
            foreach ($this->params as $param => $value) {
                $strFile = str_replace($param, $value, $strFile);
            }
            rename($this->modulesPath."/{$module}/".$file, $this->modulesPath."/{$module}/".$strFile);
        }
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
                    $result = $this->copyToDir($src.'/'.$file, $dst.'/'.$dirName, $alias, $replace,
                        $aliasOnlyFile);
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
                        var_dump($dst.'/'.$alias.$file);
                        $result = copy($src.'/'.$file, $dst.'/'.$alias.$file);
                    }
                }
            }
            closedir($dir);
        }

        return $result;
    }
}
