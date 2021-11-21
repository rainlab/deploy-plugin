<?php namespace RainLab\Deploy;

use System\Classes\PluginBase;
use System\Classes\CombineAssets;

/**
 * Plugin
 */
class Plugin extends PluginBase
{
    /**
     * @var string PROTOCOL_VERSION
     */
    const PROTOCOL_VERSION = '2.0';

    /**
     * register
     */
    public function register()
    {
        $this->registerAssetBundles();
    }

    /**
     * boot
     */
    public function boot()
    {
    }

    /**
     * registerAssetBundles for compiling assets
     */
    protected function registerAssetBundles()
    {
        CombineAssets::registerCallback(function ($combiner) {
            $combiner->registerBundle('$/rainlab/deploy/assets/less/deploy.less');
        });
    }
}
