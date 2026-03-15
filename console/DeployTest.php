<?php namespace RainLab\Deploy\Console;

use Illuminate\Console\Command;
use RainLab\Deploy\Models\Server;
use Exception;

/**
 * DeployTest tests beacon connectivity for a server
 *
 * @package rainlab/deploy
 * @author Alexey Bobkov, Samuel Georges
 */
class DeployTest extends Command
{
    use \RainLab\Deploy\Console\Traits\ResolvesServer;

    /**
     * @var string signature for the console command
     */
    protected $signature = 'deploy:test {server : Server name or ID}';

    /**
     * @var string description of the console command
     */
    protected $description = 'Test beacon connectivity for a server.';

    /**
     * handle executes the console command
     */
    public function handle()
    {
        $server = $this->resolveServer($this->argument('server'));

        $this->info("Testing beacon for: {$server->server_name}");
        $this->line("Endpoint: {$server->endpoint_url}");
        $this->newLine();

        try {
            $server->testBeacon();
        }
        catch (Exception $ex) {
            // testBeacon handles its own status internally
        }

        $statusLabels = [
            Server::STATUS_ACTIVE => '<fg=green>Active</>',
            Server::STATUS_READY => '<fg=yellow>Ready (needs install)</>',
            Server::STATUS_LEGACY => '<fg=yellow>Legacy (needs upgrade)</>',
            Server::STATUS_UNREACHABLE => '<fg=red>Unreachable</>',
        ];

        $statusLabel = $statusLabels[$server->status_code] ?? $server->status_code;

        $this->line("Status: {$statusLabel}");
        $this->line("Beacon Version: " . ($server->beacon_version ?: 'Unknown'));
        $this->line("Last Deploy: " . ($server->last_deploy_at ? $server->last_deploy_at->diffForHumans() : 'Never'));

        if ($server->status_code === Server::STATUS_UNREACHABLE) {
            $this->newLine();
            $this->error('Could not contact beacon.');
            return 1;
        }

        $this->newLine();
        $this->info('Beacon is alive!');
        return 0;
    }
}
