<?php namespace RainLab\Deploy\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class ServersAddBeaconVersion extends Migration
{
    public function up()
    {
        Schema::table('rainlab_deploy_servers', function($table) {
            $table->string('beacon_version')->nullable();
        });
    }

    public function down()
    {
        Schema::table('rainlab_deploy_servers', function ($table) {
            $table->dropColumn('beacon_version');
        });
    }
}
