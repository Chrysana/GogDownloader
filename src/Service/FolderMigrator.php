<?php

namespace App\Service;

use App\DTO\GameDetail;
use App\DTO\GameInstaller;
use App\Enum\NamingConvention;
use App\Service\Persistence\PersistenceManager;
use Throwable;

/**
 * Moves already downloaded game folders from one naming convention to another.
 *
 * Every step is planned first (so it can be previewed) and nothing is ever merged or overwritten:
 * when both the old and the new folder exist, the game is reported as a conflict and left alone.
 */
final class FolderMigrator
{
    public const MOVE = 'move';

    public const DONE = 'already-done';

    public const MISSING = 'not-downloaded';

    public const CONFLICT = 'conflict';

    public const UNSUPPORTED = 'unsupported';

    public const MOVED = 'moved';

    public const FAILED = 'failed';

    public function __construct(
        private readonly GameFolderNamer $namer,
        private readonly PersistenceManager $persistence,
    ) {
    }

    /**
     * @return list<array{game: string, from: string, to: string, status: string, message?: string}>
     */
    public function plan(string $baseDirectory, NamingConvention $from, NamingConvention $to): array
    {
        $this->namer->reset();
        $baseDirectory = rtrim($baseDirectory, '/\\');
        $isStream = (bool) preg_match('@^[0-9a-zA-Z.]+://@', $baseDirectory);

        $plan = [];
        $seen = [];
        foreach ($this->persistence->getLocalGameData() ?? [] as $game) {
            foreach ($this->getPathPairs($game, $from, $to) as [$oldRelative, $newRelative]) {
                $key = mb_strtolower($oldRelative) . '|' . mb_strtolower($newRelative);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $old = "{$baseDirectory}/{$oldRelative}";
                $new = "{$baseDirectory}/{$newRelative}";
                $entry = ['game' => $game->title, 'from' => $old, 'to' => $new];

                $oldExists = is_dir($old);
                $newExists = is_dir($new);
                $sameFolder = mb_strtolower($oldRelative) === mb_strtolower($newRelative);

                $entry['status'] = match (true) {
                    $oldRelative === $newRelative => $oldExists ? self::DONE : self::MISSING,
                    // only the letter case differs: on Windows / macOS both paths point to the same folder
                    $sameFolder && $oldExists => self::MOVE,
                    !$oldExists => $newExists ? self::DONE : self::MISSING,
                    $newExists => self::CONFLICT,
                    $isStream => self::UNSUPPORTED,
                    default => self::MOVE,
                };
                if ($entry['status'] === self::CONFLICT) {
                    $entry['message'] = 'both folders exist, move or merge the files manually';
                } elseif ($entry['status'] === self::UNSUPPORTED) {
                    $entry['message'] = 'folders in remote storage cannot be renamed';
                }
                $plan[] = $entry;
            }
        }

        usort($plan, fn (array $a, array $b) => strnatcasecmp($a['game'], $b['game']));

        return $plan;
    }

    /**
     * Executes the "move" entries of a plan.
     *
     * @param list<array{game: string, from: string, to: string, status: string, message?: string}> $plan
     *
     * @return list<array{game: string, from: string, to: string, status: string, message?: string}> the plan with updated statuses
     */
    public function execute(array $plan): array
    {
        foreach ($plan as $index => $entry) {
            if ($entry['status'] !== self::MOVE) {
                continue;
            }

            try {
                $this->move($entry['from'], $entry['to']);
                $plan[$index]['status'] = self::MOVED;
            } catch (Throwable $e) {
                $plan[$index]['status'] = self::FAILED;
                $plan[$index]['message'] = $e->getMessage();
            }
        }

        return $plan;
    }

    /**
     * Folders in the download directory that don't belong to any known game under any naming convention.
     *
     * @return list<string>
     */
    public function findUnknownFolders(string $baseDirectory): array
    {
        $baseDirectory = rtrim($baseDirectory, '/\\');
        if (!is_dir($baseDirectory) || preg_match('@^[0-9a-zA-Z.]+://@', $baseDirectory)) {
            return [];
        }

        $known = ['win' => true, 'linux' => true, 'mac' => true];
        foreach ($this->persistence->getLocalGameData() ?? [] as $game) {
            foreach (NamingConvention::cases() as $convention) {
                try {
                    $known[mb_strtolower($this->namer->getFolderName($game, $convention))] = true;
                } catch (Throwable) {
                    // e.g. no slug
                }
            }
        }

        $unknown = [];
        foreach (scandir($baseDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir("{$baseDirectory}/{$entry}") || str_starts_with($entry, '.')) {
                continue;
            }
            if (!isset($known[mb_strtolower($entry)])) {
                $unknown[] = $entry;
            }
        }
        natcasesort($unknown);

        return array_values($unknown);
    }

    /**
     * @return list<array{string, string}> old and new relative paths; more than one for platform specific layouts
     */
    private function getPathPairs(GameDetail $game, NamingConvention $from, NamingConvention $to): array
    {
        $items = [null];
        foreach ($game->downloads as $download) {
            if ($download instanceof GameInstaller) {
                $items[] = $download;
            }
        }

        $pairs = [];
        foreach ($items as $item) {
            try {
                $pairs[] = [
                    $this->namer->getRelativePath($game, $from, $item),
                    $this->namer->getRelativePath($game, $to, $item),
                ];
            } catch (Throwable) {
                // e.g. no slug stored for the game, nothing to migrate
            }
        }

        return $pairs;
    }

    private function move(string $from, string $to): void
    {
        $parent = dirname($to);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new \RuntimeException("Cannot create the folder '{$parent}'");
        }

        // case-only renames need a temporary name on case-insensitive file systems
        if (mb_strtolower($from) === mb_strtolower($to)) {
            $temporary = $from . '.renaming-' . bin2hex(random_bytes(3));
            if (!@rename($from, $temporary)) {
                throw new \RuntimeException($this->lastError("Cannot rename '{$from}'"));
            }
            $from = $temporary;
        }

        if (!@rename($from, $to)) {
            throw new \RuntimeException($this->lastError("Cannot rename '{$from}' to '{$to}'"));
        }
    }

    private function lastError(string $message): string
    {
        $error = error_get_last()['message'] ?? null;

        return $error ? "{$message}: {$error}" : $message;
    }
}
