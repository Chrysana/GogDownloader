<?php

namespace App\Trait;

use App\DTO\DownloadableItem;
use App\DTO\GameDetail;
use App\Enum\NamingConvention;
use App\Enum\Setting;
use App\Service\GameFolderNamer;
use Symfony\Component\Console\Input\InputInterface;

trait TargetDirectoryTrait
{
    private ?GameFolderNamer $gameFolderNamer = null;

    private function getTargetDir(InputInterface $input, GameDetail $game, ?DownloadableItem $download = null, ?string $subdirectory = null, ?NamingConvention $namingScheme = null): string
    {
        $namingScheme ??= NamingConvention::tryFrom(
            $this->persistence->getSetting(Setting::NamingConvention) ?? '',
        ) ?? NamingConvention::GogSlug;

        $this->gameFolderNamer ??= new GameFolderNamer($this->persistence);
        $dir = $this->getBaseDownloadDir($input) . '/' . $this->gameFolderNamer->getRelativePath($game, $namingScheme, $download);
        if ($subdirectory !== null) {
            $dir .= "/{$subdirectory}";
        }

        return $dir;
    }

    private function getBaseDownloadDir(InputInterface $input): string
    {
        $dir = ($input->hasArgument('directory') ? $input->getArgument('directory') : null)
            ?? $_ENV['DOWNLOAD_DIRECTORY']
            ?? $this->persistence->getSetting(Setting::DownloadPath)
            ?? getcwd()
        ;

        return self::makeAbsolutePath((string) $dir);
    }

    private static function makeAbsolutePath(string $dir): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // absolute paths: C:\dir, C:/dir, UNC paths (\\server\share) and stream wrappers (s3://...)
            if (!preg_match('@^(?:[a-zA-Z]:[\\\\/]|[\\\\/]{2}|[0-9a-zA-Z.]+://)@', $dir)) {
                $dir = getcwd() . '/' . $dir;
            }
        } elseif (!str_starts_with($dir, '/') && !preg_match('@^[0-9a-zA-Z.]+://.+$@', $dir)) {
            $dir = getcwd() . '/' . $dir;
        }

        return $dir;
    }
}
