<?php namespace RainLab\Deploy\Models;

use Http;
use Model;
use Carbon\Carbon;
use System\Classes\UpdateManager;
use ValidationException;
use ApplicationException;
use Exception;

/**
 * Server Model
 */
class Server extends Model
{
    use \October\Rain\Database\Traits\Validation;

    const STATUS_ACTIVE = 'active';
    const STATUS_READY = 'ready';
    const STATUS_LEGACY = 'legacy';
    const STATUS_UNREACHABLE = 'unreachable';

    /**
     * @var string table associated with the model
     */
    public $table = 'rainlab_deploy_servers';

    /**
     * @var array rules for validation
     */
    public $rules = [
        'server_name' => 'required',
        'endpoint_url' => 'required'
    ];

    /**
     * @var array dates
     */
    protected $dates = ['last_deploy_at'];

    /**
     * @var array jsonable attribute names that are json encoded and decoded from the database
     */
    protected $jsonable = ['deploy_preferences'];

    /**
     * @var array hasOne and other relations
     */
    public $hasOne = [
        'key' => ServerKey::class
    ];

    /**
     * beforeCreate
     */
    public function beforeCreate()
    {
        $this->status_code = self::STATUS_UNREACHABLE;
    }

    /**
     * beforeValidate event
     */
    public function beforeValidate()
    {
        if (!$this->exists || ($this->isDirty('private_key') && $this->private_key)) {
            $this->processLocalPrivateKey();
        }

        unset($this->private_key);
    }

    /**
     * getStatusLabelAttribute shows a human version of status code
     */
    public function getStatusLabelAttribute()
    {
        return title_case($this->status_code);
    }

    /**
     * testBeacon and return true if the status differs
     */
    public function testBeacon(): bool
    {
        $wantCode = null;
        $beaconVersion = null;

        try {
            $response = $this->transmit('healthCheck');
            $isInstalled = $response['appInstalled'] ?? false;
            $envFound = $response['envFound'] ?? false;
            $beaconVersion = $response['beaconVersion'] ?? '1.0';
            if ($isInstalled && !$envFound) {
                $wantCode = static::STATUS_LEGACY;
            }
            elseif (!$isInstalled) {
                $wantCode = static::STATUS_READY;
            }
            else {
                $wantCode = static::STATUS_ACTIVE;
            }
        }
        catch (Exception $ex) {
            $wantCode = static::STATUS_UNREACHABLE;
        }

        $differs = false;

        // Version differs
        if ($this->beacon_version !== $beaconVersion) {
            $this->beacon_version = $beaconVersion;
            $differs = true;
        }

        // Status differs
        if ($wantCode !== null && $wantCode !== $this->status_code) {
            $this->status_code = $wantCode;
            $differs = true;
        }

        if ($differs) {
            $this->save();
            return true;
        }

        return false;
    }

    /**
     * touchLastDeploy
     */
    public function touchLastDeploy()
    {
        $this->last_deploy_at = Carbon::now();
        $this->save();
    }

    /**
     * touchLastVersion
     */
    public function touchLastVersion()
    {
        $this->last_version = UpdateManager::instance()->getCurrentVersion();
        $this->save();
    }

    /**
     * setDeployPreferences manages the deployment preferences as a multidimensional array
     */
    public function setDeployPreferences(string $key, $data)
    {
        $this->deploy_preferences = [$key => $data] + (array) $this->deploy_preferences;
    }

    /**
     * transmitArtisan command to the server
     */
    public function transmitArtisan($command): array
    {
        return $this->transmit('artisanCommand', ['artisan' => $command]);
    }

    /**
     * transmitScript to execute on the server
     */
    public function transmitScript($scriptName, array $vars = []): array
    {
        $scriptPath = plugins_path("rainlab/deploy/beacon/scripts/${scriptName}.txt");

        $scriptContents = base64_encode(file_get_contents($scriptPath));

        return $this->transmit('evalScript', ['script' => $scriptContents, 'scriptVars' => $vars]);
    }

    /**
     * transmitShell command to the server
     */
    public function transmitShell($contents): array
    {
        $scriptContents = base64_encode($contents);

        return $this->transmit('shellScript', ['script' => $scriptContents]);
    }

    /**
     * transmitFile to the server
     */
    public function transmitFile(string $filePath, array $params = []): array
    {
        $payload = $this->preparePayload('fileUpload', $params);
        $endpointUrl = $this->endpoint_url;

        // @deprecated remove in 2.0
        if ($this->beacon_version === '1.0' || !$this->beacon_version) {
            $endpointUrl = $this->buildUrl('fileUpload', $params);
        }

        $response = Http::post($endpointUrl, function($http) use ($payload, $filePath) {
            $http->dataFile('file', $filePath);
            $http->data('filename', md5($filePath));
            $http->data('filehash', md5_file($filePath));
            $http->data($payload);
            $http->maxRedirects = 0;
        });

        return $this->processTransmitResponse($response);
    }

    /**
     * transmit data to the server
     */
    public function transmit(string $cmd, array $params = []): array
    {
        $payload = $this->preparePayload($cmd, $params);
        $endpointUrl = $this->endpoint_url;

        // @deprecated remove in 2.0
        if ($this->beacon_version === '1.0' || !$this->beacon_version) {
            $endpointUrl = $this->buildUrl($cmd, $params);
        }

        $response = Http::post($endpointUrl, function ($http) use ($payload) {
            $http->data($payload);
        });

        return $this->processTransmitResponse($response);
    }

    /**
     * buildUrl for the beacon with GET vars
     * @deprecated remove in v2.0
     */
    protected function buildUrl(string $cmd, array $params = []): string
    {
        return $this->endpoint_url . '?' . http_build_query($this->preparePayload($cmd, $params));
    }

    /**
     * processTransmitResponse handles the beacon response
     */
    protected function processTransmitResponse($response)
    {
        if (get('debug') === '1') {
            traceLog($response);
        }

        // Request size too large
        if ($response->code === 413) {
            throw new ApplicationException('Server did not accept the upload (Request too large)');
        }

        // Redirects seem to drop the POST variables and this is a security precaution
        if (in_array($response->code, [301, 302])) {
            $redirectTo = array_get($response->info, 'redirect_url');
            $redirectTo = explode("?", $redirectTo)[0];
            throw new ApplicationException(
                'Server responded with redirect ('.$redirectTo.')'
                . ' please update the server address to exactly this and try again.'
            );
        }

        if ($response->code !== 201 && $response->code !== 400) {
            throw new ApplicationException(
                'A valid response from a beacon was not found.'
                . ' '
                . 'Add ?debug=1 to your URL, try again and check the logs'
                . ' '
                . '(Code: '.$response->code.')'
            );
        }

        $body = json_decode($response->body, true);

        if ($response->code === 400) {
            throw new ApplicationException($body['error'] ?? 'Unspecified error from beacon');
        }

        if (!is_array($body)) {
            throw new ApplicationException('Empty response from beacon');
        }

        return $body;
    }

    /**
     * preparePayload for the beacon to process
     */
    protected function preparePayload(string $cmd, array $params = []): array
    {
        $key = $this->key;

        $params['cmd'] = $cmd;
        $params['nonce'] = $this->createNonce();

        $data = base64_encode(json_encode($params));

        $toSend = [
            'X_OCTOBER_BEACON' => $key->keyId(),
            'X_OCTOBER_BEACON_PAYLOAD' => $data,
            'X_OCTOBER_BEACON_SIGNATURE' => $key->signData($data)
        ];

        return $toSend;
    }

    /**
     * createNonce based on millisecond time
     */
    protected function createNonce(): int
    {
        $mt = explode(' ', microtime());
        return intval($mt[1] . substr($mt[0], 2, 6));
    }

    /**
     * processLocalPrivateKey will check the private_key attribute locally
     * validate it and transform to a related model
     */
    protected function processLocalPrivateKey(): void
    {
        try {
            if (!strlen(trim($this->private_key))) {
                throw new ValidationException(['private_key' => 'Deployment Key is a required field']);
            }

            // Validate key value
            $serverKey = new ServerKey;
            $serverKey->privkey = $this->private_key;
            $serverKey->validatePrivateKey();

            // Set key relationship instead of attribute
            $this->key = $serverKey;
        }
        catch (Exception $ex) {
            throw new ValidationException(['private_key' => $ex->getMessage()]);
        }
    }

    /**
     * getPluginsOptions returns an array of available plugins to deploy
     */
    public function getPluginsOptions(): array
    {
        return \System\Models\PluginVersion::all()->lists('code', 'code');
    }

    /**
     * getThemesOptions returns an array of available themes to deploy
     */
    public function getThemesOptions(): array
    {
        $result = [];

        foreach (\Cms\Classes\Theme::all() as $theme) {
            if ($theme->isLocked()) {
                $label = $theme->getConfigValue('name').' ('.$theme->getDirName().'*)';
            }
            else {
                $label = $theme->getConfigValue('name').' ('.$theme->getDirName().')';
            }

            $result[$theme->getDirName()] = $label;
        }

        return $result;
    }
}
