<?php

namespace QCH\Core\Console\Commands;

use DirectoryIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ModuleClone extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'module:clone {moduleFrom} {modulesTo}  ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clone module';

    protected $modulesPath = '';

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
        $this->moduleFrom = $this->argument('moduleFrom');
        $this->moduleTo = $this->argument('modulesTo');
        $this->rcopy(base_path("Modules/{$this->moduleFrom}"), base_path("Modules/{$this->moduleTo}"));

        return true;
    }

    public function rcopy($src, $dest)
    {
        // If source is not a directory stop processing
        if (! is_dir($src)) {
            return false;
        }

        // If the destination directory does not exist create it
        if (! is_dir($dest)) {
            if (! mkdir($dest)) {
                // If the destination directory could not be created stop processing
                return false;
            }
        }

        // Open the source directory to read in files
        $i = new DirectoryIterator($src);
        foreach ($i as $f) {
            if ($f->isFile()) {
                $pathFinal = "$dest/".$f->getFilename();
                $pathFinal = $this->replaceNameModule($pathFinal);
                var_dump($pathFinal);
                copy($f->getRealPath(), $pathFinal);
                $content = file_get_contents($pathFinal);
                $content = $this->replaceNameModule($content);

                $this->info("Clone file {$pathFinal}");
                file_put_contents($pathFinal, $content);
            } else {
                if (! $f->isDot() && $f->isDir()) {
                    $pathFinal = "$dest/$f";
                    $this->info("Cloning folder {$dest}/{$f}");
                    $pathFinal = $this->replaceNameModule($pathFinal);
                    $this->rcopy($f->getRealPath(), $pathFinal);
                }
            }
        }
    }

    public function replaceNameModule($pathFinal)
    {
        $pathFinal = str_replace(Str::camel($this->moduleFrom), Str::camel($this->moduleTo), $pathFinal);
        $pathFinal = str_replace(Str::snake($this->moduleFrom), Str::snake($this->moduleTo), $pathFinal);
        $pathFinal = str_replace(Str::kebab($this->moduleFrom), Str::kebab($this->moduleTo), $pathFinal);
        $pathFinal = str_replace(strtolower($this->moduleFrom), strtolower($this->moduleTo), $pathFinal);
        $pathFinal = str_replace(Str::studly($this->moduleFrom), Str::studly($this->moduleTo), $pathFinal);
        $pathFinal = str_replace(Str::title($this->moduleFrom), Str::title($this->moduleTo), $pathFinal);

        return $pathFinal;
    }
}
