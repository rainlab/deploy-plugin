<?php namespace RainLab\Deploy\Controllers;

use Backend\Classes\SettingsController;

/**
 * Servers Backend Controller
 */
class Servers extends SettingsController
{
    public $implement = [
        \Backend\Behaviors\FormController::class,
        \Backend\Behaviors\ListController::class
    ];

    /**
     * @var string formConfig file
     */
    public $formConfig = 'config_form.yaml';

    /**
     * @var string listConfig file
     */
    public $listConfig = 'config_list.yaml';

    /**
     * @var string settingsItemCode determines the settings code
     */
    public $settingsItemCode = 'deploy';

    /**
     * create action
     */
    public function create()
    {
        $this->addJs('/plugins/rainlab/deploy/assets/js/servers.js', 'RainLab.Deploy');

        $this->addJs('/plugins/rainlab/deploy/assets/vendor/forge/forge.min.js', 'RainLab.Deploy');

        return $this->asExtension('FormController')->create();
    }
}
