<?php namespace RainLab\Deploy\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreateServersTable extends Migration
{
    public function up()
    {
        Schema::create('rainlab_deploy_servers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('server_name')->nullable();
            $table->string('endpoint_url')->nullable();
            $table->string('status_code')->nullable();
            $table->mediumText('deploy_preferences')->nullable();
            $table->string('last_version')->nullable();
            $table->timestamp('last_deploy_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('rainlab_deploy_servers');
    }
}
