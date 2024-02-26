<?php namespace RainLab\Deploy\Controllers;

use Db;
use Str;
use Flash;
use System;
use Config;
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
        'shell_script' => '/plugins/rainlab/deploy/models/server/fields_shell_script.yaml',
        'upgrade_legacy' => '/plugins/rainlab/deploy/models/server/fields_upgrade_legacy.yaml',
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
     * update action
     */
    public function update($recordId = null)
    {
        $this->addJs('/plugins/rainlab/deploy/assets/js/servers.js', 'RainLab.Deploy');
        $this->addJs('/plugins/rainlab/deploy/assets/vendor/forge/forge.min.js', 'RainLab.Deploy');

        return $this->asExtension('FormController')->update($recordId);
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

            case $model::STATUS_LEGACY:
                $context = 'manage_legacy';
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
        $filePath = temp_path("ocbl-{$fileId}.arc");

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

        $this->vars['actionTitle'] = 'Update Environment Variables';
        $this->vars['actionHandler'] = 'onSaveEnvConfig';
        $this->vars['submitText'] = 'Save Config';
        $this->vars['closeText'] = 'Cancel';
        $this->vars['widget'] = $widget;

        return $this->makePartial('action_form');
    }

    /**
     * manage_onSaveEnvConfig saves environment variables
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
     * manage_onLoadRunShell shows the shell script form
     */
    public function manage_onLoadRunShell()
    {
        $widget = $this->formWidgetInstances['shell_script'];

        $lastScript = $widget->model->deploy_preferences['last_shell_script'] ?? "echo 'Hello World';";

        $widget->setFormValues(['shell_script' => $lastScript]);

        $this->vars['actionTitle'] = 'Run Console Script';
        $this->vars['actionHandler'] = 'onRunShellScript';
        $this->vars['submitText'] = 'Run';
        $this->vars['closeText'] = 'Close';
        $this->vars['widget'] = $widget;

        return $this->makePartial('shell_form');
    }

    /**
     * manage_onRunShellScript runs a shell script
     */
    public function manage_onRunShellScript()
    {
        $widget = $this->formWidgetInstances['shell_script'];
        $model = $widget->model;

        // Save preferences
        $model->setDeployPreferences('last_shell_script', post('shell_script'));
        $model->save();

        try {
            $response = $model->transmitShell(post('shell_script'));
            $output = $response['output'] ?? '';
            $output = base64_decode($output);
        }
        catch (Exception $ex) {
            $output = $ex->getMessage();
        }

        $widget->setFormValues([
            'shell_output' => $output
        ]);

        $fieldObject = $widget->getField('shell_output');

        return ['#'.$fieldObject->getId('group') => $widget->makePartial('field', ['field' => $fieldObject])];
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
     * manage_onSaveDeployToServer deploys selected objects to the server
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

        if (post('deploy_core')) {
            $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Core', 'buildCoreModules');
            $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Vendor', 'buildVendorPackages');
        }

        if (post('deploy_config')) {
            $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Config', 'buildConfigFiles');
        }

        if (post('deploy_app')) {
            $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'App', 'buildAppFiles');
        }

        if (post('deploy_media')) {
            $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Media', 'buildMediaFiles');
        }

        if ($plugins = post('plugins')) {
            $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Plugins', 'buildPluginsBundle', [(array) $plugins]);
        }

        if ($themes = post('themes')) {
            $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Themes', 'buildThemesBundle', [(array) $themes]);
        }

        if (count($useFiles)) {
            $deployActions[] = [
                'label' => 'Extracting Files',
                'action' => 'extractFiles',
                'files' => $useFiles
            ];
        }

        $deployActions[] = [
            'label' => 'Clearing Cache',
            'action' => 'transmitScript',
            'script' => 'clear_cache'
        ];

        $deployActions[] = [
            'label' => 'Migrating Database',
            'action' => 'transmitArtisan',
            'artisan' => 'october:migrate'
        ];

        if (post('deploy_core')) {
            $this->injectSetBuildStep($deployActions);
        }

        $deployActions[] = [
            'label' => 'Finishing Up',
            'action' => 'final',
            'files' => $useFiles,
            'deploy_core' => post('deploy_core')
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
     * manage_onSaveInstallToServer runs the installation process
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

        $deployActions[] = [
            'label' => 'Checking Database Config',
            'action' => 'transmitScript',
            'script' => 'check_database',
            'vars' => [
                'type' => post('db_type'),
                'host' => post('db_host'),
                'port' => post('db_port'),
                'name' => post('db_type') === 'sqlite' ? post('db_filename') : post('db_name'),
                'user' => post('db_user'),
                'pass' => post('db_pass')
            ]
        ];

        $envContents = ArchiveBuilder::instance()->buildEnvContents($envValues);
        $deployActions[] = [
            'label' => 'Saving Configuration Values',
            'action' => 'transmitScript',
            'script' => 'put_env_file',
            'vars' => [
                'contents' => $envContents
            ]
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

        $projectKey = \System\Models\Parameter::get('system::project.key');
        $deployActions[] = [
            'label' => 'Saving Configuration Values',
            'action' => 'transmitScript',
            'script' => 'put_project_key',
            'vars' => [
                'project' => $projectKey
            ]
        ];

        $deployActions[] = [
            'label' => 'Migrating Database',
            'action' => 'transmitArtisan',
            'artisan' => 'october:migrate'
        ];

        $this->injectSetBuildStep($deployActions);

        $deployActions[] = [
            'label' => 'Finishing Up',
            'action' => 'final',
            'files' => $useFiles,
            'deploy_core' => true
        ];

        return $this->deployerWidget->executeSteps($serverId, $deployActions);
    }

    /**
     * injectSetBuildStep
     */
    protected function injectSetBuildStep(array &$deployActions): void
    {
        $build = \System\Models\Parameter::get('system::core.build', 0);

        $deployActions[] = [
            'label' => 'Setting Build Number',
            'action' => 'transmitArtisan',
            'artisan' => 'october:util set build --value='.$build
        ];
    }

    /**
     * manage_onLoadUpgradeLegacy upgrades a legacy version
     */
    public function manage_onLoadUpgradeLegacy()
    {
        $widget = $this->formWidgetInstances['upgrade_legacy'];

        $this->vars['actionTitle'] = 'Upgrade Config';
        $this->vars['actionHandler'] = 'onRunUpgradeLegacy';
        $this->vars['submitText'] = 'Run';
        $this->vars['closeText'] = 'Close';
        $this->vars['widget'] = $widget;

        return $this->makePartial('action_form');
    }

    /**
     * manage_onRunUpgradeLegacy deploys selected objects to the server
     */
    public function manage_onRunUpgradeLegacy($serverId)
    {
        // Create deployment chain
        $deployActions = [];

        $useFiles = [];
        $useFiles[] = $this->buildArchiveDeployStep($deployActions, 'Legacy', 'buildLegacyBundle');

        $deployActions[] = [
            'label' => 'Extracting Files',
            'action' => 'extractFiles',
            'files' => $useFiles
        ];

        $deployActions[] = [
            'label' => 'Upgrading Legacy Site',
            'action' => 'transmitArtisan',
            'artisan' => 'october:env'
        ];

        $deployActions[] = [
            'label' => 'Finishing Up',
            'action' => 'final',
            'files' => $useFiles
        ];

        return $this->deployerWidget->executeSteps($serverId, $deployActions);
    }

    /**
     * buildArchiveDeployStep builds a single archive step used for deployment
     */
    protected function buildArchiveDeployStep(&$steps, string $typeLabel, string $buildFunc, array $funcArgs = []): string
    {
        $fileId = md5(uniqid());
        $filePath = temp_path("ocbl-{$fileId}.arc");

        $steps[] = [
            'label' => __('Building :type Archive', ['type' => $typeLabel]),
            'action' => 'archiveBuilder',
            'func' => $buildFunc,
            'args' => array_merge([$filePath], $funcArgs)
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

        $statusDiffers = $server->testBeacon();

        if ($server->status_code === $server::STATUS_UNREACHABLE) {
            Flash::warning('Could not contact beacon');
        }
        else {
            Flash::success('Beacon is alive!');
        }

        if ($statusDiffers) {
            return Redirect::refresh();
        }
    }

    /**
     * makeAllFormWidgets generates all form widgets used by the controller
     */
    protected function makeAllFormWidgets()
    {
        $server = ($serverId = post('server_id'))
            ? $this->formFindModelObject($serverId)
            : new \RainLab\Deploy\Models\Server;

        foreach ($this->formWidgetDefinitions as $key => $definition) {
            $config = $this->makeConfig(base_path($definition));
            $config->model = $server;
            $config->alias = Str::camel($key);

            $widget = $this->makeWidget(\Backend\Widgets\Form::class, $config);
            $widget->bindToController();

            $this->applyFormWidgetFilter($key, $widget);
            $this->formWidgetInstances[$key] = $widget;
        }

        // Remove themes without module
        if (!System::hasModule('Cms')) {
            $deployWidget = $this->formWidgetInstances['deploy'];
            $deployWidget->removeField('themes');
        }
    }

    /**
     * applyFormWidgetFilter
     */
    protected function applyFormWidgetFilter($key, $widget)
    {
        // Hide the App Files field if no app directory is found
        if (
            $key === 'deploy' &&
            !is_dir(app_path()) &&
            ($appField = $widget->getField('deploy_app'))
        ) {
            $appField->hidden = true;
        }

        // Hide the media field is media storage is not local
        if (
            Config::get('filesystems.disks.media.driver') !== 'local' &&
            ($mediaField = $widget->getField('deploy_media'))
        ) {
            $mediaField->hidden = true;
        }
    }
}
