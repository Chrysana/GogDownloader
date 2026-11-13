<?php

namespace App\Service\Factory;

use App\Enum\Setting;
use App\Service\MigrationManager;
use App\Service\Persistence\PersistenceManager;
use App\Service\Persistence\PersistenceManagerFiles;
use App\Service\Persistence\PersistenceManagerSqlite;
use App\Service\SecretProtector;
use App\Service\Serializer;
use PDO;
use Throwable;

final readonly class PersistenceManagerFactory
{
    public function __construct(
        private MigrationManager $migrationManager,
        private Serializer $serializer,
        private SecretProtector $protector,
    ) {
    }

    public function getPersistenceManager(): PersistenceManager
    {
        if (!self::isSqliteAvailable()) {
            return new PersistenceManagerFiles($this->protector);
        }

        $sqlite = new PersistenceManagerSqlite($this->migrationManager, $this->serializer, $this->protector);
        $this->importFileData($sqlite);

        return $sqlite;
    }

    public static function isSqliteAvailable(): bool
    {
        return class_exists(PDO::class, false) && in_array('sqlite', PDO::getAvailableDrivers(), true);
    }

    /**
     * Older builds (e.g. the Windows installer) shipped without sqlite support and stored data in plain files.
     * When such data exists and sqlite is now available, import it once so users don't lose their login and library.
     */
    private function importFileData(PersistenceManagerSqlite $sqlite): void
    {
        $files = new PersistenceManagerFiles($this->protector);
        if (!$files->hasAnyData()) {
            return;
        }

        try {
            // only import into an empty database, never overwrite existing sqlite data
            if ($sqlite->getAuthorization() !== null || count($sqlite->getLocalGameData() ?? [])) {
                return;
            }

            if ($authorization = $files->getAuthorization()) {
                $sqlite->saveAuthorization($authorization);
            }
            $games = [];
            foreach ($files->getLocalGameData() ?? [] as $game) {
                $games[$game->id] = $game; // dedupe, older versions could store duplicates
            }
            $sqlite->storeLocalGameData(array_values($games));
            foreach (Setting::cases() as $setting) {
                $value = $files->getSetting($setting);
                if ($value !== null) {
                    $sqlite->storeSetting($setting, $value);
                }
            }
            foreach ($files->getAllHashes() as $uncompressed => $compressed) {
                $sqlite->storeUncompressedHash($compressed, $uncompressed);
            }
            $sqlite->storeLibrary($files->getLibrary());

            $files->archiveData();
        } catch (Throwable $e) {
            fwrite(STDERR, "Warning: failed importing data from the old file based storage: {$e->getMessage()}\n");
        }
    }
}
