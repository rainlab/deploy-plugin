<?php namespace RainLab\Deploy\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreateServerKeysTable extends Migration
{
    public function up()
    {
        Schema::create('rainlab_deploy_server_keys', function (Blueprint $table) {
            $table->increments('id');
            $table->string('server_id')->index()->nullable();
            $table->text('privkey')->nullable();
            $table->text('pubkey')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('rainlab_deploy_server_keys');
    }
}
