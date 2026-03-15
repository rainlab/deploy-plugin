<?php namespace RainLab\Deploy;

use System\Classes\PluginBase;

/**
 * Plugin
 */
class Plugin extends PluginBase
{
    /**
     * @var string PROTOCOL_VERSION
     */
    const PROTOCOL_VERSION = '2.2';

    /**
     * register
     */
    public function register()
    {
        $this->registerConsoleCommand('deploy.server', \RainLab\Deploy\Console\DeployServer::class);
        $this->registerConsoleCommand('deploy.build', \RainLab\Deploy\Console\DeployBuild::class);
        $this->registerConsoleCommand('deploy.test', \RainLab\Deploy\Console\DeployTest::class);
        $this->registerConsoleCommand('deploy.list', \RainLab\Deploy\Console\DeployList::class);
    }
}
