<?php

/**
 * Builds gog-downloader.phar from the project directory using only PHP's built-in Phar support.
 *
 * Usage: php -d phar.readonly=0 build/build-phar.php [output-file]
 *
 * Run "composer install --no-dev --optimize-autoloader" first so development dependencies are not bundled.
 */

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is enabled, run with: php -d phar.readonly=0 build/build-phar.php\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $argv[1] ?? "{$root}/gog-downloader.phar";
if (file_exists($output)) {
    unlink($output);
}

$includeDirectories = ['bin', 'config', 'src', 'vendor'];
$includeFiles = ['composer.json', 'LICENSE'];
// paths (relative to the root) that are never needed at runtime
$excludePatterns = [
    '@^vendor/[^/]+/[^/]+/(tests?|Tests?|docs?|\.github)/@',
    '@^vendor/bin/@',
    '@\.(md|dist|neon|xsd|rst)$@i',
    '@/(phpunit|phpstan|psalm)[^/]*\.xml@i',
    '@^vendor/friendsofphp/@',
];

$phar = new Phar($output, 0, 'gog-downloader.phar');
$phar->startBuffering();

$count = 0;
foreach ($includeDirectories as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        assert($file instanceof SplFileInfo);
        if (!$file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, $relative)) {
                continue 2;
            }
        }
        $phar->addFile($file->getPathname(), $relative);
        ++$count;
    }
}
foreach ($includeFiles as $file) {
    if (is_file("{$root}/{$file}")) {
        $phar->addFile("{$root}/{$file}", $file);
        ++$count;
    }
}

$phar->setStub(<<<'STUB'
    #!/usr/bin/env php
    <?php
    Phar::mapPhar('gog-downloader.phar');
    require 'phar://gog-downloader.phar/bin/app.php';
    __HALT_COMPILER();
    STUB);

if (Phar::canCompress(Phar::GZ)) {
    $phar->compressFiles(Phar::GZ);
}
$phar->stopBuffering();

printf("Built %s (%d files, %.1f MB)\n", $output, $count, filesize($output) / 1024 / 1024);
