<?php

namespace App\Service;

use App\DTO\DownloadableItem;
use App\DTO\GameDetail;
use App\DTO\PlatformSpecificItem;
use App\Enum\NamingConvention;
use App\Enum\OperatingSystem;
use App\Exception\InvalidValueException;
use App\Service\Persistence\PersistenceManager;
use LogicException;

/**
 * Decides the folder a game is downloaded into, for every naming convention.
 */
final class GameFolderNamer
{
    /** maximum length (in characters) of a folder name created from a title */
    private const MAX_LENGTH = 120;

    private const WINDOWS_RESERVED = '/^(con|prn|aux|nul|com[0-9¹²³]|lpt[0-9¹²³])(\..*)?$/i';

    /** @var array<int, string>|null game id => folder name, for the title naming convention */
    private ?array $titleFolders = null;

    public function __construct(
        private readonly PersistenceManager $persistence,
    ) {
    }

    /**
     * Forgets cached names (call after the library or local database changed).
     */
    public function reset(): void
    {
        $this->titleFolders = null;
    }

    /**
     * The path of the game's folder relative to the download directory (may contain a platform prefix).
     */
    public function getRelativePath(GameDetail $game, NamingConvention $convention, ?DownloadableItem $download = null): string
    {
        $folder = $this->getFolderName($game, $convention);

        if ($convention === NamingConvention::RomManager) {
            $platform = 'win';
            if ($download instanceof PlatformSpecificItem) {
                $platform = match (OperatingSystem::tryFrom($download->platform)) {
                    OperatingSystem::Linux => 'linux',
                    OperatingSystem::MacOS => 'mac',
                    OperatingSystem::Windows => 'win',
                    default => throw new InvalidValueException("Unsupported operating system: {$download->platform}"),
                };
            }

            return "{$platform}/{$folder}";
        }

        return $folder;
    }

    public function getFolderName(GameDetail $game, NamingConvention $convention): string
    {
        switch ($convention) {
            case NamingConvention::GogSlug:
            case NamingConvention::RomManager:
                if (!$game->slug) {
                    throw new InvalidValueException("GOG Downloader is configured to use the GOG slug naming scheme, but the game '{$game->title}' does not have a slug. If you migrated from the previous naming scheme, please run the update command first.");
                }

                return $game->slug;
            case NamingConvention::Custom:
                $title = preg_replace('@[^a-zA-Z-_0-9.]@', '_', $game->title);
                $title = preg_replace('@_{2,}@', '_', $title);

                return trim($title, '.');
            case NamingConvention::Title:
                return $this->getTitleFolders()[$game->id] ?? $this->disambiguate(self::sanitizeTitle($game->title), $game);
        }

        throw new LogicException('Unimplemented naming scheme: ' . $convention->value);
    }

    /**
     * Turns a game title into a folder name that is valid on Windows, macOS and Linux (and network shares),
     * keeping it as close to the original title as possible.
     */
    public static function sanitizeTitle(string $title): string
    {
        if (!mb_check_encoding($title, 'UTF-8')) {
            $title = mb_convert_encoding($title, 'UTF-8', 'UTF-8');
        }

        // trademark-like symbols are decoration, not part of the name
        $name = preg_replace('/[®™©℠℗]/u', '', $title);
        // "Title: Subtitle" reads best as "Title - Subtitle"
        $name = preg_replace('/\s*:\s*/u', ' - ', $name);
        // path separators and pipes become dashes so words don't run together ("AC/DC" -> "AC-DC")
        $name = preg_replace('/\s*[\/\\\\|]\s*/u', '-', $name);
        // double quotes are not allowed, use single ones
        $name = str_replace(['"', '“', '”', '„'], "'", $name);
        // whitespace of any kind (tabs, newlines, non-breaking spaces) becomes a single regular space
        $name = preg_replace('/\s+/u', ' ', $name);
        // remaining characters forbidden on Windows, control characters and invisible formatting characters
        $name = preg_replace('/[<>?*\x00-\x1F\x7F]|\p{Cf}/u', '', $name);
        $name = preg_replace('/ {2,}/', ' ', $name);
        // repeated separators produced by the replacements above
        $name = preg_replace('/(\s-\s)+/u', ' - ', $name);
        $name = preg_replace('/^[\s-]+|[\s-]+$/u', '', $name);
        // Windows doesn't allow names ending with a dot or space
        $name = rtrim($name, '. ');

        if (mb_strlen($name) > self::MAX_LENGTH) {
            $cut = mb_substr($name, 0, self::MAX_LENGTH);
            $lastSpace = mb_strrpos($cut, ' ');
            if ($lastSpace !== false && $lastSpace > self::MAX_LENGTH / 2) {
                $cut = mb_substr($cut, 0, $lastSpace);
            }
            $name = rtrim($cut, '. -');
        }

        if (preg_match(self::WINDOWS_RESERVED, $name)) {
            $name .= '_';
        }
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }

        return $name;
    }

    /**
     * @return array<int, string> folder names for all known games; when several games end up with the same
     *                            name (e.g. "BioShock® 2" and "BioShock™ 2"), the one with the lowest id keeps it
     *                            and the others get their slug appended, so folders of existing games never change
     */
    private function getTitleFolders(): array
    {
        if ($this->titleFolders !== null) {
            return $this->titleFolders;
        }

        $titles = [];
        $slugs = [];
        foreach ($this->persistence->getLibrary() as $item) {
            $titles[$item->id] = $item->title;
            $slugs[$item->id] = $item->slug;
        }
        foreach ($this->persistence->getLocalGameData() ?? [] as $game) {
            $titles[$game->id] ??= $game->title;
            if (!isset($slugs[$game->id]) || $slugs[$game->id] === '') {
                $slugs[$game->id] = $game->slug;
            }
        }
        ksort($titles);

        $used = [];
        $result = [];
        foreach ($titles as $id => $title) {
            $name = self::sanitizeTitle($title);
            if ($name === '') {
                $name = $slugs[$id] ?: (string) $id;
            }
            // case-insensitive, because Windows and macOS file systems are
            $key = mb_strtolower($name);
            if (isset($used[$key])) {
                $name = self::withSuffix($name, $slugs[$id] ?: (string) $id);
                $key = mb_strtolower($name);
                if (isset($used[$key])) {
                    $name = self::withSuffix($name, (string) $id);
                    $key = mb_strtolower($name);
                }
            }
            $used[$key] = true;
            $result[$id] = $name;
        }

        return $this->titleFolders = $result;
    }

    private function disambiguate(string $name, GameDetail $game): string
    {
        return $name !== '' ? $name : ($game->slug ?: (string) $game->id);
    }

    private static function withSuffix(string $name, string $suffix): string
    {
        return "{$name} ({$suffix})";
    }
}
