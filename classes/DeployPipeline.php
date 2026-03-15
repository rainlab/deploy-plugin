<?php namespace RainLab\Deploy\Classes;

use RainLab\Deploy\Models\Server as ServerModel;
use ApplicationException;
use Exception;

/**
 * DeployPipeline executes deployment steps without HTTP dependency
 *
 * @package rainlab/deploy
 * @author Alexey Bobkov, Samuel Georges
 */
class DeployPipeline
{
    /**
     * @var ServerModel server to deploy to
     */
    protected $server;

    /**
     * @var array fileMap maps local archive paths to remote paths after transmit
     */
    protected $fileMap = [];

    /**
     * @var callable|null stepCallback fired before each step
     */
    protected $stepCallback;

    /**
     * @var callable|null successCallback fired after each step succeeds
     */
    protected $successCallback;

    /**
     * __construct
     */
    public function __construct(ServerModel $server)
    {
        $this->server = $server;
    }

    /**
     * setStepCallback registers a callback for step progress
     */
    public function setStepCallback(callable $callback): self
    {
        $this->stepCallback = $callback;
        return $this;
    }

    /**
     * setSuccessCallback registers a callback after step success
     */
    public function setSuccessCallback(callable $callback): self
    {
        $this->successCallback = $callback;
        return $this;
    }

    /**
     * executeSteps runs a list of deployment step arrays sequentially
     */
    public function executeSteps(array $steps): void
    {
        @set_time_limit(3600);

        foreach ($steps as $index => $step) {
            if ($this->stepCallback) {
                ($this->stepCallback)($step, $index, count($steps));
            }

            $result = $this->executeStep($step);

            if ($this->successCallback) {
                ($this->successCallback)($step, $result, $index, count($steps));
            }
        }
    }

    /**
     * executeStep runs a single deployment step
     */
    public function executeStep(array $step): ?array
    {
        $action = $step['action'] ?? null;

        switch ($action) {
            case 'archiveBuilder':
                return $this->handleArchiveBuilder($step);

            case 'transmitFile':
                return $this->handleTransmitFile($step);

            case 'transmitArtisan':
                return $this->handleTransmitArtisan($step);

            case 'transmitScript':
                return $this->handleTransmitScript($step);

            case 'extractFiles':
                return $this->handleExtractFiles($step);

            case 'final':
                return $this->handleFinal($step);

            default:
                throw new ApplicationException("Unknown deploy action: {$action}");
        }
    }

    /**
     * handleArchiveBuilder
     */
    protected function handleArchiveBuilder(array $step): ?array
    {
        $func = $step['func'] ?? null;
        $args = $step['args'] ?? null;
        if (!$func || !$args) {
            throw new ApplicationException('Missing function or args');
        }

        ArchiveBuilder::instance()->$func(...$args);

        return null;
    }

    /**
     * handleTransmitFile
     */
    protected function handleTransmitFile(array $step): array
    {
        $file = $step['file'] ?? null;
        if (!$file) {
            throw new ApplicationException('Missing file');
        }

        $response = $this->server->transmitFile($file);
        $remotePath = base64_decode($response['path']);
        $this->fileMap[$file] = $remotePath;

        return ['path' => $remotePath];
    }

    /**
     * handleTransmitArtisan
     */
    protected function handleTransmitArtisan(array $step): array
    {
        $artisanCmd = $step['artisan'] ?? null;
        if (!$artisanCmd) {
            throw new ApplicationException('Missing artisan command');
        }

        $response = $this->server->transmitArtisan($artisanCmd);

        $errCode = $response['errCode'] ?? null;
        $output = isset($response['output']) ? base64_decode($response['output']) : 'Missing output';
        if ((int) $errCode !== 0) {
            throw new ApplicationException($output);
        }

        return ['output' => $output];
    }

    /**
     * handleTransmitScript
     */
    protected function handleTransmitScript(array $step): ?array
    {
        $scriptName = $step['script'] ?? null;
        $scriptVars = $step['vars'] ?? [];
        if (!$scriptName) {
            throw new ApplicationException('Missing script');
        }

        $response = $this->server->transmitScript($scriptName, $scriptVars);
        $statusCode = $response['status'] ?? null;
        if ($statusCode !== 'ok') {
            throw new ApplicationException($response['error'] ?? 'Script failed');
        }

        return null;
    }

    /**
     * handleExtractFiles
     */
    protected function handleExtractFiles(array $step): ?array
    {
        $fileList = $step['files'] ?? null;
        if (!$fileList || !is_array($fileList)) {
            throw new ApplicationException('Missing file map. Nothing to deploy?');
        }

        // Build remote file map from tracked transmissions
        $remoteFileMap = [];
        foreach ($fileList as $localFile) {
            if (isset($this->fileMap[$localFile])) {
                $remoteFileMap[$localFile] = $this->fileMap[$localFile];
            }
        }

        $response = $this->server->transmitScript('extract_archive', [
            'files' => $remoteFileMap
        ]);

        $statusCode = $response['status'] ?? null;
        if ($statusCode !== 'ok') {
            throw new ApplicationException($response['error'] ?? 'Unzip failed');
        }

        return null;
    }

    /**
     * handleFinal
     */
    protected function handleFinal(array $step): ?array
    {
        // Clean up local temp files
        $files = $step['files'] ?? [];
        if (is_array($files)) {
            foreach ($files as $file) {
                if (starts_with($file, temp_path())) {
                    @unlink($file);
                }
            }
        }

        $this->server->testBeacon();
        $this->server->touchLastDeploy();

        if ($step['deploy_core'] ?? false) {
            $this->server->touchLastVersion();
        }

        return null;
    }
}
