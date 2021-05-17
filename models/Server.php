<?php namespace RainLab\Deploy\Models;

use Http;
use Model;
use Exception;
use ValidationException;

/**
 * Server Model
 */
class Server extends Model
{
    use \October\Rain\Database\Traits\Validation;

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
     * @var array hasOne and other relations
     */
    public $hasOne = [
        'key' => ServerKey::class
    ];

    /**
     * beforeValidate event
     */
    public function beforeValidate()
    {
        if ($this->private_key) {
            $this->processLocalPrivateKey();
        }
    }

    /**
     * transmit data to the server
     */
    public function transmit(string $cmd, array $params = []): array
    {
        $response = Http::get($this->buildUrl($cmd, $params));

        if ($response->code !== 201) {
            throw new Exception('Invalid response from Beacon');
        }

        return json_decode($response->body, true);
    }

    /**
     * buildUrl
     */
    protected function buildUrl(string $cmd, array $params = []): string
    {
        return $this->endpoint_url . '?' . http_build_query($this->preparePayload($cmd, $params));
    }

    /**
     * preparePayload
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
        return $mt[1] . substr($mt[0], 2, 6);
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
            unset($this->private_key);
            $this->key = $serverKey;
        }
        catch (Exception $ex) {
            throw new ValidationException(['private_key' => $ex->getMessage()]);
        }
    }
}
