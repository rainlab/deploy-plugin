<?php namespace RainLab\Deploy\Console;

use Illuminate\Console\Command;
use RainLab\Deploy\Models\Server;

/**
 * DeployList lists all configured deployment servers
 *
 * @package rainlab/deploy
 * @author Alexey Bobkov, Samuel Georges
 */
class DeployList extends Command
{
    /**
     * @var string signature for the console command
     */
    protected $signature = 'deploy:list';

    /**
     * @var string description of the console command
     */
    protected $description = 'List all configured deployment servers.';

    /**
     * handle executes the console command
     */
    public function handle()
    {
        $servers = Server::all();

        if ($servers->isEmpty()) {
            $this->warn('No servers configured. Add servers in the backend under Settings > Deploy.');
            return 0;
        }

        $rows = $servers->map(function ($server) {
            return [
                $server->id,
                $server->server_name,
                $server->endpoint_url,
                $server->status_code ? title_case($server->status_code) : 'Unknown',
                $server->beacon_version ?: '-',
                $server->last_deploy_at ? $server->last_deploy_at->format('Y-m-d H:i') : 'Never',
            ];
        });

        $this->table(
            ['ID', 'Name', 'Endpoint', 'Status', 'Beacon', 'Last Deploy'],
            $rows
        );

        return 0;
    }
}
