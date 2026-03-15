<?php namespace RainLab\Deploy\Console;

use Illuminate\Console\Command;
use RainLab\Deploy\Classes\ArchiveBuilder;
use RainLab\Deploy\Classes\DeployPipeline;
use RainLab\Deploy\Models\Server;
use Exception;

/**
 * Deploy command performs a full deployment to a remote server
 *
 * @package rainlab/deploy
 * @author Alexey Bobkov, Samuel Georges
 */
class DeployServer extends Command
{
    use \RainLab\Deploy\Console\Traits\ResolvesServer;

    /**
     * @var string signature for the console command
     */
    protected $signature = 'deploy:server
        {server : Server name or ID}
        {--core : Deploy core modules and vendor packages}
        {--config : Deploy config files}
        {--app : Deploy app files}
        {--media : Deploy media files}
        {--plugins=* : Plugin codes to deploy}
        {--themes=* : Theme codes to deploy}
        {--all : Deploy everything}
        {--f|force : Skip confirmation}';

    /**
     * @var string description of the console command
     */
    protected $description = 'Deploy files to a remote server.';

    /**
     * handle executes the console command
     */
    public function handle()
    {
        $server = $this->resolveServer($this->argument('server'));

        if ($server->status_code === Server::STATUS_UNREACHABLE) {
            $this->error("Server \"{$server->server_name}\" is unreachable. Run deploy:test first.");
            return 1;
        }

        // Determine what to deploy
        $deployCore = $this->option('all') || $this->option('core');
        $deployConfig = $this->option('all') || $this->option('config');
        $deployApp = $this->option('all') || $this->option('app');
        $deployMedia = $this->option('all') || $this->option('media');
        $plugins = $this->option('plugins');
        $themes = $this->option('themes');

        if ($this->option('all')) {
            $plugins = array_keys($server->getPluginsOptions());
            if (class_exists(\Cms\Classes\Theme::class)) {
                $themes = array_keys($server->getThemesOptions());
            }
        }

        // If nothing specified, prompt interactively
        if (!$deployCore && !$deployConfig && !$deployApp && !$deployMedia
            && empty($plugins) && empty($themes)) {
            return $this->handleInteractive($server);
        }

        // Build step chain
        $steps = $this->buildDeploySteps(
            $deployCore, $deployConfig, $deployApp, $deployMedia,
            $plugins, $themes
        );

        if (empty($steps)) {
            $this->warn('Nothing selected to deploy.');
            return 0;
        }

        // Confirm
        $this->info("Deploying to: {$server->server_name} ({$server->endpoint_url})");
        $this->displayDeploySummary($deployCore, $deployConfig, $deployApp, $deployMedia, $plugins, $themes);

        if (!$this->option('force') && !$this->confirm('Proceed with deployment?')) {
            return 0;
        }

        return $this->executePipeline($server, $steps);
    }

    /**
     * handleInteractive prompts the user for what to deploy
     */
    protected function handleInteractive(Server $server): int
    {
        $deployCore = $this->confirm('Deploy core modules and vendor?', false);
        $deployConfig = $this->confirm('Deploy config files?', false);
        $deployApp = is_dir(app_path()) ? $this->confirm('Deploy app files?', false) : false;
        $deployMedia = $this->confirm('Deploy media files?', false);

        $pluginOptions = $server->getPluginsOptions();
        $plugins = [];
        if (!empty($pluginOptions) && $this->confirm('Deploy plugins?', false)) {
            $plugins = $this->choice(
                'Select plugins to deploy',
                $pluginOptions,
                null,
                null,
                true
            );
        }

        $themeOptions = [];
        if (class_exists(\Cms\Classes\Theme::class)) {
            $themeOptions = $server->getThemesOptions();
        }

        $themes = [];
        if (!empty($themeOptions) && $this->confirm('Deploy themes?', false)) {
            $themes = $this->choice(
                'Select themes to deploy',
                $themeOptions,
                null,
                null,
                true
            );
        }

        $steps = $this->buildDeploySteps(
            $deployCore, $deployConfig, $deployApp, $deployMedia,
            $plugins, $themes
        );

        if (empty($steps)) {
            $this->warn('Nothing selected to deploy.');
            return 0;
        }

        return $this->executePipeline($server, $steps);
    }

    /**
     * executePipeline runs the deployment pipeline with progress output
     */
    protected function executePipeline(Server $server, array $steps): int
    {
        $progressBar = $this->output->createProgressBar(count($steps));
        $progressBar->setFormat(' %current%/%max% [%bar%] %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        $pipeline = new DeployPipeline($server);

        $pipeline->setStepCallback(function ($step) use ($progressBar) {
            $progressBar->setMessage($step['label'] ?? 'Processing...');
        });

        $pipeline->setSuccessCallback(function () use ($progressBar) {
            $progressBar->advance();
        });

        try {
            $pipeline->executeSteps($steps);
            $progressBar->setMessage('Complete!');
            $progressBar->finish();
            $this->newLine(2);
            $this->info('Deployment successful!');
            return 0;
        }
        catch (Exception $ex) {
            $progressBar->finish();
            $this->newLine(2);
            $this->error('Deployment failed: ' . $ex->getMessage());
            return 1;
        }
    }

    /**
     * buildDeploySteps creates the deployment step chain
     */
    protected function buildDeploySteps(
        bool $deployCore,
        bool $deployConfig,
        bool $deployApp,
        bool $deployMedia,
        array $plugins,
        array $themes
    ): array {
        $steps = [];
        $useFiles = [];

        if ($deployCore) {
            $useFiles[] = $this->addArchiveDeployStep($steps, 'Core', 'buildCoreModules');
            $useFiles[] = $this->addArchiveDeployStep($steps, 'Vendor', 'buildVendorPackages');
        }

        if ($deployConfig) {
            $useFiles[] = $this->addArchiveDeployStep($steps, 'Config', 'buildConfigFiles');
        }

        if ($deployApp) {
            $useFiles[] = $this->addArchiveDeployStep($steps, 'App', 'buildAppFiles');
        }

        if ($deployMedia) {
            $useFiles[] = $this->addArchiveDeployStep($steps, 'Media', 'buildMediaFiles');
        }

        if (!empty($plugins)) {
            $useFiles[] = $this->addArchiveDeployStep($steps, 'Plugins', 'buildPluginsBundle', [$plugins]);
        }

        if (!empty($themes)) {
            $useFiles[] = $this->addArchiveDeployStep($steps, 'Themes', 'buildThemesBundle', [$themes]);
        }

        if (count($useFiles)) {
            $steps[] = [
                'label' => 'Extracting Files',
                'action' => 'extractFiles',
                'files' => $useFiles
            ];
        }

        $steps[] = [
            'label' => 'Clearing Cache',
            'action' => 'transmitScript',
            'script' => 'clear_cache'
        ];

        $steps[] = [
            'label' => 'Migrating Database',
            'action' => 'transmitArtisan',
            'artisan' => 'october:migrate'
        ];

        if ($deployCore) {
            $build = \System\Models\Parameter::get('system::core.build', 0);
            $steps[] = [
                'label' => 'Setting Build Number',
                'action' => 'transmitArtisan',
                'artisan' => 'october:util set build --value=' . $build
            ];
        }

        $steps[] = [
            'label' => 'Finishing Up',
            'action' => 'final',
            'files' => $useFiles,
            'deploy_core' => $deployCore
        ];

        return $steps;
    }

    /**
     * addArchiveDeployStep creates build + transmit step pair
     */
    protected function addArchiveDeployStep(
        array &$steps,
        string $typeLabel,
        string $buildFunc,
        array $funcArgs = []
    ): string {
        $fileId = md5(uniqid());
        $filePath = temp_path("ocbl-{$fileId}.arc");

        $steps[] = [
            'label' => "Building {$typeLabel} Archive",
            'action' => 'archiveBuilder',
            'func' => $buildFunc,
            'args' => array_merge([$filePath], $funcArgs)
        ];

        $steps[] = [
            'label' => "Deploying {$typeLabel} Archive",
            'action' => 'transmitFile',
            'file' => $filePath
        ];

        return $filePath;
    }

    /**
     * displayDeploySummary shows what will be deployed
     */
    protected function displayDeploySummary($core, $config, $app, $media, $plugins, $themes): void
    {
        $items = [];
        if ($core) $items[] = 'Core + Vendor';
        if ($config) $items[] = 'Config';
        if ($app) $items[] = 'App';
        if ($media) $items[] = 'Media';
        if (!empty($plugins)) $items[] = 'Plugins: ' . implode(', ', $plugins);
        if (!empty($themes)) $items[] = 'Themes: ' . implode(', ', $themes);

        $this->line('Components: ' . implode(' | ', $items));
    }
}
