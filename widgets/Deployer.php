<?php namespace RainLab\Deploy\Widgets;

use Flash;
use Redirect;
use Backend\Classes\WidgetBase;
use RainLab\Deploy\Classes\ArchiveBuilder;
use RainLab\Deploy\Models\Server as ServerModel;
use ApplicationException;
use Exception;

/**
 * Deployer widget
 *
 * @package rainlab/deploy
 * @author Alexey Bobkov, Samuel Georges
 */
class Deployer extends WidgetBase
{
    /**
     * @var string alias used for this widget
     */
    public $alias = 'deployer';

    /**
     * loadAssets adds widget specific asset files. Use $this->addJs() and $this->addCss()
     * to register new assets to include on the page.
     */
    protected function loadAssets()
    {
        $this->addJs('js/deployer.js', 'core');
    }

    /**
     * render renders the widget
     */
    public function render(): string
    {
        return '';
    }

    /**
     * executeSteps builds the execution form
     */
    public function executeSteps($serverId, $steps)
    {
        if (!ServerModel::find($serverId)) {
            throw new ApplicationException('Could not find server');
        }

        try {
            $this->vars['serverId'] = $serverId;
            $this->vars['deploySteps'] = $steps;
        }
        catch (Exception $ex) {
            $this->handleError($ex);
        }

        return $this->makePartial('execute');
    }

    /**
     * onExecuteStep runs a specific update step
     */
    public function onExecuteStep()
    {
        // Address timeout limits
        @set_time_limit(3600);

        $stepAction = post('action');

        switch ($stepAction) {
            case 'archiveBuilder':
                $args = post('args');
                $func = post('func');
                if (!$args || !$func) {
                    throw new ApplicationException('Missing function or args');
                }

                ArchiveBuilder::instance()->$func(...$args);
                break;

            case 'transmitArtisan':
                $artisanCmd = post('artisan');
                if (!$artisanCmd) {
                    throw new ApplicationException('Missing artisan command');
                }

                $response = $this->findServerModelObject()->transmitArtisan($artisanCmd);

                $errCode = $response['errCode'] ?? null;
                $output = isset($response['output']) ? base64_decode($response['output']) : 'Missing output';
                if ((int) $errCode !== 0) {
                    throw new ApplicationException($output);
                }

                return ['output' => $output];

            case 'transmitScript':
                $scriptName = post('script');
                $scriptVars = post('vars');
                if (!$scriptName) {
                    throw new ApplicationException('Missing script or vars');
                }

                if (!$scriptVars) {
                    $scriptVars = [];
                }

                $response = $this->findServerModelObject()->transmitScript($scriptName, $scriptVars);
                $statusCode = $response['status'] ?? null;
                if ($statusCode !== 'ok') {
                    throw new ApplicationException($response['error'] ?? 'Script failed');
                }
                break;

            case 'transmitFile':
                $file = post('file');
                if (!$file) {
                    throw new ApplicationException('Missing file');
                }

                $response = $this->findServerModelObject()->transmitFile($file);
                return ['path' => base64_decode($response['path'])];

            case 'extractFiles':
                $fileMap = post('fileMap');
                if (!$fileMap || !is_array($fileMap)) {
                    throw new ApplicationException('Missing file map. Nothing to deploy?');
                }

                $response = $this->findServerModelObject()->transmitScript('extract_archive', [
                    'files' => $fileMap
                ]);

                $statusCode = $response['status'] ?? null;
                if ($statusCode !== 'ok') {
                    throw new ApplicationException($response['error'] ?? 'Unzip failed');
                }
                break;

            case 'final':
                $this->cleanupFiles(post('files'));
                $server = $this->findServerModelObject();
                $server->testBeacon();
                $server->touchLastDeploy();
                if (post('deploy_core')) {
                    $server->touchLastVersion();
                }
                Flash::success('Deployment Successful');
                return Redirect::refresh();
        }
    }

    /**
     * cleanupFiles
     */
    protected function cleanupFiles($files)
    {
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (starts_with($file, temp_path())) {
                @unlink($file);
            }
        }
    }

    /**
     * findServerModelObject
     */
    protected function findServerModelObject(): ServerModel
    {
        $server = ServerModel::find(post('serverId'));

        if (!$server) {
            throw new ApplicationException('Could not find server');
        }

        return $server;
    }
}
