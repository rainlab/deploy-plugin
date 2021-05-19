<?php namespace RainLab\Deploy\Classes;

use File;
use Exception;
use October\Rain\Filesystem\Zip;
use October\Rain\Parse\Bracket;

/**
 * ArchiveBuilder builds ZIP archives for deployment
 */
class ArchiveBuilder
{
    use \October\Rain\Support\Traits\Singleton;

    /**
     * buildBeaconFiles builds the beacon files for deployment
     */
    public function buildBeaconFiles(string $outputFilePath, string $pubKey)
    {
        $templatePath = plugins_path('rainlab/deploy/beacon/templates/app');

        $beaconContents = Bracket::parse(file_get_contents($templatePath . '/bootstrap/beacon.stub'), [
            'pub_key_encoded' => base64_encode($pubKey)
        ]);

        static::instance()->buildArchive($outputFilePath, [
            'dirs' => [
                'bootstrap'
            ],
            'files' => [
                'index.php' => file_get_contents($templatePath . '/index.stub'),
                'bootstrap/app.php' => file_get_contents($templatePath . '/bootstrap/app.stub'),
                'bootstrap/autoload.php' => file_get_contents($templatePath . '/bootstrap/autoload.stub'),
                'bootstrap/beacon.php' => $beaconContents,
            ]
        ]);
    }

    /**
     * buildCoreModules builds the core modules
     */
    public function buildCoreModules(string $outputFilePath)
    {
        static::instance()->buildArchive($outputFilePath, [
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
        static::instance()->buildArchive($outputFilePath, [
            'dirs' => [
                'vendor'
            ],
            'dirsSrc' => [
                'vendor' => base_path('vendor')
            ]
        ]);
    }

    /**
     * buildEnvVariables builds an archive with the environment variable file in it
     */
    public static function buildEnvVariables(string $outputFilePath, string $contents): void
    {
        static::instance()->buildArchive($outputFilePath, [
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

        try {
            // Build directories
            $dirs = $options['dirs'] ?? [];
            foreach ($dirs as $dirName) {
                File::makeDirectory($tmpPath . '/' . $dirName, 0777, true);
            }

            // Build files
            $files = $options['files'] ?? [];
            foreach ($files as $filename => $contents) {
                file_put_contents($tmpPath . '/' . $filename, $contents);
            }

            // Build archive file
            Zip::make($outputFilePath, function($zip) use ($tmpPath, $options) {
                $zip->add($tmpPath . '/{*,.[!.]*,..?*}');

                $filesSrc = $options['filesSrc'] ?? [];
                foreach ($filesSrc as $fileSrc) {
                    $zip->add($fileSrc);
                }

                $dirsSrc = $options['dirsSrc'] ?? [];
                foreach ($dirsSrc as $folderName => $dirSrc) {
                    $zip->folder($folderName, rtrim($dirSrc, '/') . '/*');
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
    public function getTempPath()
    {
        return temp_path();
    }

    /**
     * createTempPath creates the working path on the disk
     */
    public function createTempPath($uniqueId)
    {
        $path = $this->getTempPath() . '/' . $uniqueId;
        if (!File::exists($path)) {
            File::makeDirectory($path, 0777, true);
        }
        else {
            File::cleanDirectory($path);
        }

        return $path;
    }

    /**
     * destroyTempPath removes the working path and files from disk
     */
    public function destroyTempPath($uniqueId)
    {
        try {
            $path = $this->createTempPath($uniqueId);
            return File::deleteDirectory($path);
        }
        catch (Exception $ex) {
            traceLog('Warning: unable to delete temporary path: ' . $path);
        }
    }
}
