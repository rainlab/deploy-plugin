<?php namespace RainLab\Deploy\Console;

use File;
use Illuminate\Console\Command;
use RainLab\Deploy\Classes\ArchiveBuilder;
use RainLab\Deploy\Models\Server;
use Exception;

/**
 * DeployBuild builds deployment archives locally without transmitting
 *
 * @package rainlab/deploy
 * @author Alexey Bobkov, Samuel Georges
 */
class DeployBuild extends Command
{
    /**
     * @var string signature for the console command
     */
    protected $signature = 'deploy:build
        {--core : Build core modules archive}
        {--vendor : Build vendor packages archive}
        {--config : Build config files archive}
        {--app : Build app files archive}
        {--media : Build media files archive}
        {--plugins=* : Plugin codes to include}
        {--themes=* : Theme codes to include}
        {--all : Build all archives}
        {--output= : Output directory (defaults to temp path)}';

    /**
     * @var string description of the console command
     */
    protected $description = 'Build deployment archives locally without transmitting.';

    /**
     * handle executes the console command
     */
    public function handle()
    {
        $outputDir = $this->option('output') ?: temp_path();

        if (!File::isDirectory($outputDir)) {
            $this->error("Output directory does not exist: {$outputDir}");
            return 1;
        }

        $builder = ArchiveBuilder::instance();
        $builds = [];

        if ($this->option('all') || $this->option('core')) {
            $builds['core'] = ['buildCoreModules', []];
        }

        if ($this->option('all') || $this->option('vendor')) {
            $builds['vendor'] = ['buildVendorPackages', []];
        }

        if ($this->option('all') || $this->option('config')) {
            $builds['config'] = ['buildConfigFiles', []];
        }

        if ($this->option('all') || $this->option('app')) {
            $builds['app'] = ['buildAppFiles', []];
        }

        if ($this->option('all') || $this->option('media')) {
            $builds['media'] = ['buildMediaFiles', []];
        }

        $plugins = $this->option('plugins');
        if ($this->option('all')) {
            $plugins = array_keys((new Server)->getPluginsOptions());
        }
        if (!empty($plugins)) {
            $builds['plugins'] = ['buildPluginsBundle', [$plugins]];
        }

        $themes = $this->option('themes');
        if ($this->option('all') && class_exists(\Cms\Classes\Theme::class)) {
            $themes = array_keys((new Server)->getThemesOptions());
        }
        if (!empty($themes)) {
            $builds['themes'] = ['buildThemesBundle', [$themes]];
        }

        if (empty($builds)) {
            $this->warn('No archive type specified. Use --core, --vendor, --config, --app, --media, --plugins, --themes, or --all.');
            return 0;
        }

        $this->info("Building archives in: {$outputDir}");
        $this->newLine();

        $rows = [];
        foreach ($builds as $name => [$method, $args]) {
            $filePath = $outputDir . "/deploy-{$name}.zip";
            $this->line("Building {$name}...");

            try {
                $startTime = microtime(true);
                $builder->$method($filePath, ...$args);
                $elapsed = round(microtime(true) - $startTime, 1);
                $size = $this->formatFileSize(File::size($filePath));

                $rows[] = [$name, $filePath, $size, "{$elapsed}s"];
                $this->info("  Done ({$size}, {$elapsed}s)");
            }
            catch (Exception $ex) {
                $this->error("  Failed: " . $ex->getMessage());
                $rows[] = [$name, $filePath, 'FAILED', '-'];
            }
        }

        $this->newLine();
        $this->table(['Archive', 'Path', 'Size', 'Time'], $rows);

        return 0;
    }

    /**
     * formatFileSize returns a human-readable file size
     */
    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
