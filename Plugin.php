<?php namespace RainLab\Deploy;

use System\Classes\PluginBase;
use System\Classes\CombineAssets;

/**
 * Plugin
 */
class Plugin extends PluginBase
{
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
