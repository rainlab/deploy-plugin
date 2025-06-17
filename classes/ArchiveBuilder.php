<?php namespace RainLab\Deploy\Classes;

use File;
use Exception;
use System\Classes\PluginManager;
use October\Rain\Filesystem\Zip;
use October\Rain\Parse\Bracket;
use RainLab\Deploy\Classes\GitIgnorer;

/**
 * ArchiveBuilder builds ZIP archives for deployment
 */
class ArchiveBuilder
{
    use \October\Rain\Support\Traits\Singleton;

    /**
     * @var string templatePath for application stub files
     */
    protected $templatePath;

    /**
     * init initializes the plugin manager
     */
    protected function init()
    {
        $this->templatePath = plugins_path('rainlab/deploy/beacon/templates/app');
    }

    /**
     * buildBeaconFiles builds the beacon files for deployment
     */
    public function buildBeaconFiles(string $outputFilePath, string $pubKey)
    {
        $beaconContents = Bracket::parse(file_get_contents($this->templatePath . '/bootstrap/beacon.stub'), [
            'beacon_version' => \RainLab\Deploy\Plugin::PROTOCOL_VERSION,
            'pub_key_encoded' => base64_encode($pubKey)
        ]);

        $this->buildArchive($outputFilePath, [
            'dirs' => [
                'bootstrap'
            ],
            'files' => [
                'index.php' => file_get_contents($this->templatePath . '/index.stub'),
                'bootstrap/app.php' => file_get_contents($this->templatePath . '/bootstrap/app.stub'),
                'bootstrap/autoload.php' => file_get_contents($this->templatePath . '/bootstrap/autoload.stub'),
                'bootstrap/providers.php' => file_get_contents($this->templatePath . '/bootstrap/providers.stub'),
                'bootstrap/beacon.php' => $beaconContents,
            ]
        ]);
    }

    /**
     * buildInstallBundle builds files for a new install
     */
    public function buildInstallBundle(string $outputFilePath)
    {
        static::instance()->buildArchive($outputFilePath, [
            'dirs' => [
                'config',
                'storage',
                'tests'
            ],
            'dirsSrc' => [
                'plugins/october/demo' => base_path('plugins/october/demo'),
                'themes/demo' => base_path('themes/demo'),
                'storage' => plugins_path('rainlab/deploy/beacon/templates/storage'),
                'config' => base_path('config'),
                'tests' => base_path('tests'),
            ],
            'filesSrc' => [
                'artisan' => base_path('artisan'),
                '.htaccess' => base_path('.htaccess'),
                'server.php' => base_path('server.php'),
                'phpunit.xml' => base_path('phpunit.xml'),
                'composer.json' => base_path('composer.json'),
                'composer.lock' => base_path('composer.lock'),
            ]
        ]);
    }

    /**
     * buildLegacyBundle builds files for upgrading an older version of October CMS
     */
    public function buildLegacyBundle(string $outputFilePath)
    {
        static::instance()->buildArchive($outputFilePath, [
            'dirsSrc' => [
                'storage' => plugins_path('rainlab/deploy/beacon/templates/storage'),
            ],
        ]);
    }

    /**
     * buildPluginsBundle builds a bundle of plugins from their codes
     */
    public function buildPluginsBundle(string $outputFilePath, array $pluginCodes)
    {
        $definition = [
            'dirs' => [
                'plugins'
            ],
            'dirsSrc' => [],
            'gitIgnoreFiles' => []
        ];

        // Find plugin paths
        $pluginManager = PluginManager::instance();
        foreach ($pluginCodes as $pluginCode) {
            $path = $pluginManager->getPluginPath($pluginCode);
            if (!$path) {
                traceLog('Could not find plugin path for code: '.$pluginCode);
                continue;
            }

            $localPath = 'plugins/'.strtolower(str_replace('.', '/', $pluginCode));
            $definition['dirsSrc'][$localPath] = $path;
            $definition['gitIgnoreFiles'][$localPath] = $path.'/.deployignore';
        }

        $this->buildArchive($outputFilePath, $definition);
    }

    /**
     * buildThemesBundle builds a bundle of themes from their codes
     */
    public function buildThemesBundle(string $outputFilePath, array $themeCodes)
    {
        $definition = [
            'dirs' => [
                'themes'
            ],
            'dirsSrc' => [],
            'gitIgnoreFiles' => []
        ];

        // Find themes paths
        foreach ($themeCodes as $themeCode) {
            $localPath = 'themes/'.$themeCode;
            $path = themes_path($themeCode);
            $definition['dirsSrc'][$localPath] = $path;
            $definition['gitIgnoreFiles'][$localPath] = $path.'/.deployignore';
        }

        $this->buildArchive($outputFilePath, $definition);
    }

    /**
     * buildConfigFiles builds the config files
     */
    public function buildConfigFiles(string $outputFilePath)
    {
        $this->buildArchive($outputFilePath, [
            'dirs' => [
                'config'
            ],
            'dirsSrc' => [
                'config' => base_path('config'),
                'lang' => base_path('lang')
            ]
        ]);
    }

    /**
     * buildAppFiles builds the application files
     */
    public function buildAppFiles(string $outputFilePath)
    {
        $this->buildArchive($outputFilePath, [
            'dirs' => [
                'app'
            ],
            'dirsSrc' => [
                'app' => base_path('app'),
            ]
        ]);
    }

    /**
     * buildMediaFiles builds the media files in the storage directory
     */
    public function buildMediaFiles(string $outputFilePath)
    {
        $this->buildArchive($outputFilePath, [
            'dirs' => [
                'storage/app/media'
            ],
            'dirsSrc' => [
                'storage/app/media' => base_path('storage/app/media'),
            ]
        ]);
    }

    /**
     * buildCoreModules builds the core modules
     */
    public function buildCoreModules(string $outputFilePath)
    {
        $this->buildArchive($outputFilePath, [
            'dirs' => [
                'modules'
            ],
            'dirsSrc' => [
                'modules' => base_path('modules')
            ]
        ]);
    }

    /**
     * buildVendorPackages builds the vendor packages
     */
    public function buildVendorPackages(string $outputFilePath)
    {
        $this->buildArchive($outputFilePath, [
            'dirs' => [
                'vendor'
            ],
            'dirsSrc' => [
                'vendor' => base_path('vendor')
            ]
        ]);
    }

    /**
     * buildEnvContents builds environment variable file from values
     */
    public function buildEnvContents(array $values)
    {
        return Bracket::parse(file_get_contents($this->templatePath . '/.env.stub'), $values);
    }

    /**
     * buildEnvVariables builds an archive with the environment variable file in it
     */
    public function buildEnvVariables(string $outputFilePath, string $contents): void
    {
        $this->buildArchive($outputFilePath, [
            'files' => [
                '.env' => $contents
            ]
        ]);
    }

    /**
     * buildArchive will build an archive
     * Options:
     * - files => [filename: contents, ...]
     * - dirs => [dirname, ...]
     * - filesSrc => [filename, ...]
     * - dirsSrc => [folder: dirname, ...]
     */
    public function buildArchive(string $outputFilePath, array $options): void
    {
        $uniqueId = uniqid();
        $tmpPath = $this->createTempPath($uniqueId);

        if (!class_exists('System')) {
            $options = [];
        }

        try {
            // Build directories
            $dirs = $options['dirs'] ?? [];
            foreach ($dirs as $dirName) {
                File::makeDirectory($tmpPath . '/' . $dirName, 0755, true);
            }

            // Build files
            $files = $options['files'] ?? [];
            foreach ($files as $filename => $contents) {
                file_put_contents($tmpPath . '/' . $filename, $contents);
            }

            // Build gitignores
            $ignoreService = new GitIgnorer;
            $excludePaths = [];
            $ignoreFiles = $options['gitIgnoreFiles'] ?? [];
            foreach ($ignoreFiles as $ignoreFile) {
                if (!file_exists($ignoreFile)) {
                    continue;
                }

                $excludePaths = array_merge($excludePaths, $ignoreService->findSingle($ignoreFile));
            }

            // Build archive file
            Zip::make($outputFilePath, function($zip) use ($tmpPath, $options, $excludePaths) {
                $zip->exclude($excludePaths);

                $zip->add($tmpPath . '/{*,.[!.]*,..?*}');

                $filesSrc = $options['filesSrc'] ?? [];
                foreach ($filesSrc as $fileSrc) {
                    if (file_exists($fileSrc)) {
                        $zip->add($fileSrc);
                    }
                }

                $dirsSrc = $options['dirsSrc'] ?? [];
                foreach ($dirsSrc as $folderName => $dirSrc) {
                    if (file_exists($dirSrc)) {
                        $zip->folder($folderName, rtrim($dirSrc, '/') . '/*');
                    }
                }
            });
        }
        finally {
            // Clean up
            $this->destroyTempPath($uniqueId);
        }
    }

    /**
     * getTempPath returns a temporary working directory
     */
    protected function getTempPath(): string
    {
        return temp_path();
    }

    /**
     * createTempPath creates the working path on the disk
     */
    protected function createTempPath(string $uniqueId): string
    {
        $path = $this->getTempPath() . '/' . $uniqueId;

        if (!File::exists($path)) {
            File::makeDirectory($path, 0755, true);
        }
        else {
            File::cleanDirectory($path);
        }

        return $path;
    }

    /**
     * destroyTempPath removes the working path and files from disk
     */
    protected function destroyTempPath($uniqueId): void
    {
        try {
            $path = $this->getTempPath() . '/' . $uniqueId;

            File::deleteDirectory($path);
        }
        catch (Exception $ex) {
            traceLog('Warning: unable to delete temporary path: ' . $path);
        }
    }
}
