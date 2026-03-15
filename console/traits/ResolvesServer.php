<?php namespace RainLab\Deploy\Console\Traits;

use RainLab\Deploy\Models\Server;
use ApplicationException;

/**
 * ResolvesServer resolves a server model by name or ID
 *
 * @package rainlab/deploy
 * @author Alexey Bobkov, Samuel Georges
 */
trait ResolvesServer
{
    /**
     * resolveServer finds a server by numeric ID or name (case-insensitive)
     */
    protected function resolveServer(string $identifier): Server
    {
        if (is_numeric($identifier)) {
            $server = Server::find((int) $identifier);
            if ($server) {
                return $server;
            }
        }

        $server = Server::where('server_name', 'like', $identifier)->first();

        if (!$server) {
            throw new ApplicationException(
                "Server \"{$identifier}\" not found. Use deploy:list to see available servers."
            );
        }

        return $server;
    }
}
