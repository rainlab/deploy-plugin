<?php namespace RainLab\Deploy\Controllers;

use Backend\Classes\SettingsController;
use ApplicationException;
use Exception;
use Flash;

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
     * formWidgetDefinitions
     */
    public $formWidgetDefinitions = [
        'privkey' => '/plugins/rainlab/deploy/models/server/fields_privkey.yaml',
        'env_config' => '/plugins/rainlab/deploy/models/server/fields_env_config.yaml',
        'deploy' => '/plugins/rainlab/deploy/models/server/fields_deploy.yaml',
    ];

    /**
     * formWidgetInstances
     */
    protected $formWidgetInstances = [];

    /**
     * __construct
     */
    public function __construct()
    {
        parent::__construct();

        $this->makeAllFormWidgets();
    }

    /**
     * create action
     */
    public function create()
    {
        $this->addJs('/plugins/rainlab/deploy/assets/js/servers.js', 'RainLab.Deploy');
        $this->addJs('/plugins/rainlab/deploy/assets/vendor/forge/forge.min.js', 'RainLab.Deploy');

        return $this->asExtension('FormController')->create();
    }

    /**
     * manage action
     */
    public function manage($recordId = null)
    {
        $this->addCss('/plugins/rainlab/deploy/assets/css/deploy.css', 'RainLab.Deploy');

        return $this->asExtension('FormController')->update($recordId, 'manage');
    }

    /**
     * manage_onShowDeployKey shows the deployment key
     */
    public function manage_onShowDeployKey()
    {
        $widget = $this->formWidgetInstances['privkey'];

        $this->vars['actionTitle'] = 'View Deployment Key';
        $this->vars['closeText'] = 'Close';
        $this->vars['widget'] = $widget;

        return $this->makePartial('action_form');
    }

    /**
     * manage_onUpdateEnvConfig shows the environment variables
     */
    public function manage_onUpdateEnvConfig()
    {
        $widget = $this->formWidgetInstances['env_config'];

        try {

            $response = $widget->model->transmitScript('get_env_file');

            $envContents = $response['contents'] ?? null;

            if ($envContents === null) {
                throw new ApplicationException('Beacon did not respond with a valid file');
            }

            $widget->model->env_config = base64_decode($envContents);

        }
        catch (Exception $ex) {
            $this->handleError($ex);
        }

        $this->vars['actionTitle'] = 'Update Server Config';
        $this->vars['submitText'] = 'Save Config';
        $this->vars['closeText'] = 'Cancel';
        $this->vars['widget'] = $widget;

        return $this->makePartial('action_form');
    }

    /**
     * manage_onDeployToServer shows the deployment form
     */
    public function manage_onDeployToServer()
    {
        $widget = $this->formWidgetInstances['deploy'];

        $this->vars['actionTitle'] = 'Deploy Files to Server';
        $this->vars['submitText'] = 'Deploy';
        $this->vars['closeText'] = 'Cancel';
        $this->vars['widget'] = $widget;

        return $this->makePartial('action_form');
    }

    /**
     * manage_onTestBeacon tests the beacon connectivity
     */
    public function manage_onTestBeacon($recordId = null)
    {
        if (!$server = $this->formFindModelObject($recordId)) {
            throw new ApplicationException('Could not find server');
        }

        try {
            $response = $server->transmit('healthCheck');
            traceLog($response);
            Flash::success('Beacon is alive!');
        }
        catch (Exception $ex) {
            Flash::error('Could not contact beacon');
        }
    }

    /**
     * makeAllFormWidgets
     */
    protected function makeAllFormWidgets()
    {
        $server = ($serverId = post('server_id'))
            ? $this->formFindModelObject($serverId)
            : new \RainLab\Deploy\Models\Server;

        foreach ($this->formWidgetDefinitions as $key => $definition) {
            $config = $this->makeConfig(base_path($definition));

            $config->model = $server;

            $widget = $this->makeWidget(\Backend\Widgets\Form::class, $config);

            $widget->bindToController();

            $this->formWidgetInstances[$key] = $widget;
        }
    }
}
