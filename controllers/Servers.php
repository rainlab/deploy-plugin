<?php namespace RainLab\Deploy\Controllers;

use Db;
use Str;
use Flash;
use Backend;
use Redirect;
use Response;
use Backend\Classes\SettingsController;
use RainLab\Deploy\Classes\ArchiveBuilder;
use RainLab\Deploy\Widgets\Deployer;
use ApplicationException;
use Exception;

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
        'deploy' => '/plugins/rainlab/deploy/models/server/fields_deploy.yaml',
        'install' => '/plugins/rainlab/deploy/models/server/fields_install.yaml',
        'privkey' => '/plugins/rainlab/deploy/models/server/fields_privkey.yaml',
        'env_config' => '/plugins/rainlab/deploy/models/server/fields_env_config.yaml',
    ];

    /**
     * formWidgetInstances
     */
    protected $formWidgetInstances = [];

    /**
     * @var RainLab\Deploy\Widgets\Deployer
     */
    protected $deployerWidget;

    /**
     * beforeDisplay runs before all page actions and handlers
     */
    public function beforeDisplay()
    {
        $this->makeAllFormWidgets();

        $this->deployerWidget = new Deployer($this);
        $this->deployerWidget->bindToController();
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

        $this->pageTitle = 'Manage Server';

        $model = $this->formFindModelObject($recordId);

        switch ($model->status_code) {
            case $model::STATUS_READY:
                $context = 'manage_install';
                break;

            case $model::STATUS_UNREACHABLE:
                $context = 'manage_download';
                break;

            default:
                $context = 'manage';
                break;
        }

        $this->initForm($model, $context);
    }

    /**
     * download action
     */
    public function download($recordId = null)
    {
        if (!$server = $this->formFindModelObject($recordId)) {
            throw new ApplicationException('Could not find server');
        }

        $pubKey = $server->key->pubkey ?? null;
        if (!$pubKey) {
            throw new ApplicationException('Could not find public key');
        }

        $fileId = md5(uniqid());
        $filePath = temp_path("ocbl-${fileId}.arc");

        ArchiveBuilder::instance()->buildBeaconFiles($filePath, $pubKey);

        $outputName = Str::slug($server->server_name) . '-beacon.zip';

        return Response::download($filePath, $outputName)->deleteFileAfterSend(true);
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
     * manage_onLoadEnvConfig shows the environment variables
     */
    public function manage_onLoadEnvConfig()
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
        $this->vars['actionHandler'] = 'onSaveEnvConfig';
        $this->vars['submitText'] = 'Save Config';
        $this->vars['closeText'] = 'Cancel';
        $this->vars['widget'] = $widget;

        return $this->makePartial('action_form');
    }

    /**
     * manage_onSaveEnvConfig
     */
    public function manage_onSaveEnvConfig($serverId)
    {
        $contents = post('env_config');

        $deployActions = [
            [
                'label' => 'Saving Configuration Values',
                'action' => 'transmitScript',
                'script' => 'put_env_file',
                'vars' => ['contents' => $contents]
            ],
            [
                'label' => 'Finishing Up',
                'action' => 'final'
            ]
        ];

        return $this->deployerWidget->executeSteps($serverId, $deployActions);
    }

    /**
     * manage_onDeployToServer shows the deployment form
     */
    public function manage_onLoadDeployToServer()
    {
        $widget = $this->formWidgetInstances['deploy'];
        $model = $widget->model;

        $deployPrefs = $model->deploy_preferences['deploy_config'] ?? [];
        $widget->setFormValues($deployPrefs);

        $this->vars['actionTitle'] = 'Deploy Files to Server';
        $this->vars['actionHandler'] = 'onSaveDeployToServer';
        $this->vars['submitText'] = 'Deploy';
        $this->vars['closeText'] = 'Cancel';
        $this->vars['widget'] = $widget;

        return $this->makePartial('action_form');
    }

    /**
     * manage_onSaveEnvConfig
     */
    public function manage_onSaveDeployToServer($serverId)
    {
        $widget = $this->formWidgetInstances['deploy'];
        $model = $widget->model;

        // Save preferences
        $model->setDeployPreferences('deploy_config', post());
        $model->save();

        // Create deployment chain
        $deployActions = [];
        $useFiles = [];
        $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Core', 'buildCoreModules');
        $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Vendor', 'buildVendorPackages');

        $deployActions[] = [
            'label' => 'Extracting Files',
            'action' => 'extractFiles',
            'files' => $useFiles
        ];

        $deployActions[] = [
            'label' => 'Migrating Database',
            'action' => 'transmitArtisan',
            'artisan' => 'october:migrate'
        ];

        $deployActions[] = [
            'label' => 'Finishing Up',
            'action' => 'final',
            'files' => $useFiles
        ];

        return $this->deployerWidget->executeSteps($serverId, $deployActions);
    }

    /**
     * manage_onLoadInstallToServer shows the installation form
     */
    public function manage_onLoadInstallToServer()
    {
        $widget = $this->formWidgetInstances['install'];
        $model = $widget->model;

        $deployPrefs = $model->deploy_preferences['install_config'] ?? [];
        $widget->setFormValues($deployPrefs + [
            'app_url' => $model->endpoint_url,
            'backend_uri' => Backend::uri(),
            'db_type' => Db::getDriverName(),
            'db_host' => Db::getConfig('host'),
            'db_port' => Db::getConfig('port'),
            'db_name' => Db::getConfig('database'),
            'db_user' => Db::getConfig('username'),
            'db_filename' => 'storage/database.sqlite',
        ]);

        $this->vars['actionTitle'] = 'Install October CMS to Server';
        $this->vars['actionHandler'] = 'onSaveInstallToServer';
        $this->vars['submitText'] = 'Install';
        $this->vars['closeText'] = 'Cancel';
        $this->vars['widget'] = $widget;

        return $this->makePartial('action_form');
    }

    /**
     * manage_onSaveInstallToServer
     */
    public function manage_onSaveInstallToServer($serverId)
    {
        $widget = $this->formWidgetInstances['install'];
        $model = $widget->model;

        // Save preferences
        $model->setDeployPreferences('install_config', post());
        $model->save();

        // Build environment variables
        $envValues = post();
        if ($envValues['db_type'] === 'sqlite') {
            $envValues['db_name'] = $envValues['db_filename'];
        }
        $envValues['app_key'] = env('APP_KEY');

        // Create deployment chain
        $deployActions = [];

        $envContents = ArchiveBuilder::instance()->buildEnvContents($envValues);
        $deployActions[] = [
            'label' => 'Saving Configuration Values',
            'action' => 'transmitScript',
            'script' => 'put_env_file',
            'vars' => ['contents' => $envContents]
        ];

        $useFiles = [];
        $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Installer', 'buildInstallBundle');
        $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Core', 'buildCoreModules');
        $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Vendor', 'buildVendorPackages');

        $deployActions[] = [
            'label' => 'Extracting Files',
            'action' => 'extractFiles',
            'files' => $useFiles
        ];

        $deployActions[] = [
            'label' => 'Migrating Database',
            'action' => 'transmitArtisan',
            'artisan' => 'october:migrate'
        ];

        $deployActions[] = [
            'label' => 'Finishing Up',
            'action' => 'final',
            'files' => $useFiles
        ];

        return $this->deployerWidget->executeSteps($serverId, $deployActions);
    }

    /**
     * buildArchiveDeployStep
     */
    protected function buildArchiveDeployStep(&$steps, $typeLabel, $buildFunc): string
    {
        $fileId = md5(uniqid());
        $filePath = temp_path("ocbl-${fileId}.arc");

        $steps[] = [
            'label' => __('Building :type Archive', ['type' => $typeLabel]),
            'action' => 'archiveBuilder',
            'func' => $buildFunc,
            'args' => [$filePath]
        ];

        $steps[] = [
            'label' => __('Deploying :type Archive', ['type' => $typeLabel]),
            'action' => 'transmitFile',
            'file' => $filePath
        ];

        return $filePath;
    }

    /**
     * manage_onTestBeacon tests the beacon connectivity
     */
    public function manage_onTestBeacon($recordId = null)
    {
        if (!$server = $this->formFindModelObject($recordId)) {
            throw new ApplicationException('Could not find server');
        }

        $wantCode = null;

        try {
            $response = $server->transmit('healthCheck');
            $isInstalled = $response['appInstalled'] ?? false;
            $wantCode = $isInstalled ? $server::STATUS_ACTIVE : $server::STATUS_READY;
            Flash::success('Beacon is alive!');
        }
        catch (Exception $ex) {
            $wantCode = $server::STATUS_UNREACHABLE;
            Flash::warning('Could not contact beacon');
        }

        // Status differs
        if ($wantCode !== null && $wantCode !== $server->status_code) {
            $server->status_code = $wantCode;
            $server->save();

            return Redirect::refresh();
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
