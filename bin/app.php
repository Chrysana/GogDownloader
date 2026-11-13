#!/usr/bin/env php
<?php

use App\AppKernel;
use Symfony\Component\Console\Application;

require_once __DIR__ . "/../vendor/autoload.php";

// used by the interactive interface to start itself again for background tasks
define('GOG_DOWNLOADER_ENTRYPOINT', __FILE__);

$kernel = new AppKernel("cli", $_ENV['GOG_DOWNLOADER_DEBUG'] ?? false);
$kernel->boot();

$container = $kernel->getContainer();
$application = $container->get(Application::class);
assert($application instanceof Application);
$application->setName('gog-downloader');
$application->setVersion(
    file_exists(__DIR__ . '/appversion')
    ? trim(file_get_contents(__DIR__ . '/appversion'))
    : 'dev-version',
);

// started without any arguments in a terminal (or by double clicking the executable): open the interactive interface
if (count($_SERVER['argv'] ?? []) <= 1 && stream_isatty(STDIN) && stream_isatty(STDOUT)) {
    $application->setDefaultCommand('tui');
}

$application->run();
