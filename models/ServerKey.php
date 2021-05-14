<?php namespace RainLab\Deploy\Models;

use Model;
use SystemException;

/**
 * ServerKey Model
 */
class ServerKey extends Model
{
    use \October\Rain\Database\Traits\Validation;

    /**
     * @var string table associated with the model
     */
    public $table = 'rainlab_deploy_server_keys';

    /**
     * @var array rules for validation
     */
    public $rules = [];

    /**
     * @var array belongsTo and other relations
     */
    public $belongsTo = [
        'server' => Server::class
    ];

    /**
     * validatePrivateKey will check the private keypair for validity
     */
    public function validatePrivateKey()
    {
        $resource = $this->openPrivateKey($this->privkey);

        $this->pubkey = openssl_pkey_get_details($resource)['key'];
    }

    /**
     * signData using the server key
     */
    public function signData(string $data): string
    {
        $resource = $this->openPrivateKey($this->privkey);

        $signature = null;

        openssl_sign($data, $signature, $resource);

        return base64_encode($signature);
    }

    /**
     * verifySignature matches the server key
     */
    public function verifySignature($data, $signature)
    {
        $sigBin = base64_decode($signature);

        $resource = openssl_pkey_get_public($this->pubkey);

        return openssl_verify($data, $sigBin, $resource);
    }

    /**
     * openPrivateKey resource for OpenSSL
     */
    protected function openPrivateKey($privKey)
    {
        $resource = openssl_pkey_get_private($privKey);

        if ($resource === false) {
            throw new SystemException(sprintf(
                "Could not process private key: '%s'",
                openssl_error_string()
            ));
        }

        $privateKey = null;
        $export = openssl_pkey_export($resource, $privateKey);
        if ($export === false) {
            throw new SystemException(sprintf(
                "Could not export private key: '%s'",
                openssl_error_string()
            ));
        }

        return $resource;
    }
}
