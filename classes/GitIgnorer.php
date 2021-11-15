<?php namespace RainLab\Deploy\Classes;

use File;

/**
 * GitIgnorer scans .gitignore files and locates paths to ignore
 */
class GitIgnorer
{
    /**
     * @var string ignoreFileName
     */
    protected $ignoreFileName = '.gitignore';

    /**
     * findRecursive will recursively check for .gitignore files and then extract the paths.
     */
    public function findRecursive(string $path): array
    {
        $exclude = [];

        $files = $this->findIgnoreFiles($path);

        foreach ($files as $file) {
            $exclude = array_merge($exclude, $this->findSingle($file));
        }

        return $exclude;
    }

    /**
     * findSingle scans a single .gitignore file and then extracts the paths.
     */
    public function findSingle(string $file): array
    {
        $exclude = $this->parseIgnoreFile($file);

        $exclude = array_filter(array_map('realpath', $exclude));

        return $exclude;
    }

    /**
     * findIgnoreFiles locates all .gitignore files in a path.
     */
    protected function findIgnoreFiles(string $path, array $files = []): array
    {
        $file = $path.'/'.$this->ignoreFileName;
        if (is_file($file)) {
            $files[] = $file;
        }

        foreach (File::directories($path) as $directory) {
            $files = $this->findIgnoreFiles($directory, $files);
        }

        return $files;
    }

    /**
     * parseIgnoreFile opens a .gitignore file and returns any paths that are ignored.
     */
    protected function parseIgnoreFile(string $file): array
    {
        $matches = [];
        $dir = dirname($file);
        $lines = file($file);

        foreach ($lines as $line) {
            $line = trim($line);

            // Empty
            if ($line === '') {
                continue;
            }

            // Comment
            if (substr($line, 0, 1) == '#') {
                continue;
            }

            // Inverse
            if (substr($line, 0, 1) == '!') {
                $line = substr($line, 1);
                $files = array_diff(glob($dir.'/*'), glob($dir.'/'.$line));
            }
            else {
                $files = glob($dir.'/'.$line);
            }

            $matches = array_merge($matches, $files);
        }

        return $matches;
    }
}
