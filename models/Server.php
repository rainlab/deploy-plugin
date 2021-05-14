<?php namespace RainLab\Deploy\Models;

use Model;

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
    public $rules = [];

    /**
     * @var array hasOne and other relations
     */
    public $hasOne = [
        'key' => ServerKey::class
    ];
}
