<?php

namespace App\Service\Persistence;

use App\DTO\Authorization;
use App\DTO\GameDetail;
use App\DTO\LibraryItem;
use App\Enum\Setting;
use App\Service\SecretProtector;
use DateTime;
use DateTimeImmutable;
use Error;
use ReflectionObject;
use Stringable;

final class PersistenceManagerFiles extends AbstractPersistenceManager
{
    private const AUTH_FILE = 'auth.db';

    private const GAME_FILE = 'games.db';

    private const SETTINGS_FILE = 'settings.db';

    private const HASHES_FILE = 'hashes.db';

    private const LIBRARY_FILE = 'library.json';

    public function __construct(
        private readonly SecretProtector $protector = new SecretProtector(),
    ) {
    }

    public function getAuthorization(): ?Authorization
    {
        $file = $this->getFullPath(self::AUTH_FILE);
        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $wasProtected = $this->protector->isProtected($content);
        $content = $this->protector->unprotect($content);
        if ($content === null) {
            return null;
        }

        $authorization = @unserialize($content, [
            'allowed_classes' => [Authorization::class, DateTimeImmutable::class, DateTime::class],
        ]);
        if (!$authorization instanceof Authorization) {
            return null;
        }

        // stored in plain text by an older version, encrypt it now
        if (!$wasProtected && $this->protector->getMethod() !== 'none') {
            $this->saveAuthorization($authorization);
        }

        return $authorization;
    }

    public function saveAuthorization(?Authorization $authorization): self
    {
        file_put_contents(
            $this->getFullPath(self::AUTH_FILE),
            $authorization === null ? $this->serialize(null) : $this->protector->protect($this->serialize($authorization)),
        );

        return $this;
    }

    public function storeLocalGameData(array $details): void
    {
        file_put_contents(
            $this->getFullPath(self::GAME_FILE),
            $this->serialize($details),
        );
    }

    public function getLocalGameData(): ?array
    {
        $file = $this->getFullPath(self::GAME_FILE);
        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        return @unserialize($content) ?: null;
    }

    public function storeSingleGameDetail(GameDetail $detail): void
    {
        // replace an existing entry for the same game instead of appending a duplicate
        $details = array_values(array_filter(
            $this->getLocalGameData() ?? [],
            fn (GameDetail $existing) => $existing->id !== $detail->id,
        ));
        $details[] = $detail;
        $this->storeLocalGameData($details);
    }

    public function storeSetting(Setting $setting, float|bool|int|string|null $value): void
    {
        $file = $this->getFullPath(self::SETTINGS_FILE);
        $content = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        $content[$setting->value] = $value;

        file_put_contents($file, json_encode($content));
    }

    public function getSetting(Setting $setting): int|string|float|bool|null
    {
        $file = $this->getFullPath(self::SETTINGS_FILE);
        if (!file_exists($file)) {
            return null;
        }
        $content = json_decode(file_get_contents($file), true);

        return $content[$setting->value] ?? null;
    }

    public function getCompressedHash(string $uncompressedHash): ?string
    {
        $file = $this->getFullPath(self::HASHES_FILE);
        if (!file_exists($file)) {
            return null;
        }
        $content = json_decode(file_get_contents($file), true);

        return $content[$uncompressedHash] ?? null;
    }

    public function storeUncompressedHash(string $compressedHash, string $uncompressedHash): void
    {
        $file = $this->getFullPath(self::HASHES_FILE);
        $content = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        $content[$uncompressedHash] = $compressedHash;

        file_put_contents($file, json_encode($content));
    }

    public function needsMigrating(bool $excludeEmpty = false): bool
    {
        return false;
    }

    public function storeLibrary(array $items): void
    {
        file_put_contents(
            $this->getFullPath(self::LIBRARY_FILE),
            json_encode(array_map(
                fn (LibraryItem $item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'slug' => $item->slug,
                    'hidden' => $item->hidden,
                    'tags' => array_values($item->tags),
                ],
                array_values($items),
            ), JSON_THROW_ON_ERROR),
        );
    }

    public function getLibrary(): array
    {
        $file = $this->getFullPath(self::LIBRARY_FILE);
        if (!file_exists($file)) {
            return [];
        }

        $result = [];
        foreach (json_decode(file_get_contents($file), true) ?: [] as $row) {
            $result[(int) $row['id']] = new LibraryItem(
                id: (int) $row['id'],
                title: $row['title'],
                slug: $row['slug'] ?? '',
                hidden: (bool) ($row['hidden'] ?? false),
                tags: $row['tags'] ?? [],
            );
        }
        uasort($result, fn (LibraryItem $a, LibraryItem $b) => strcasecmp($a->title, $b->title));

        return $result;
    }

    /**
     * Whether there's any data stored using this persistence manager in the config directory.
     */
    public function hasAnyData(): bool
    {
        foreach ([self::AUTH_FILE, self::GAME_FILE, self::SETTINGS_FILE, self::HASHES_FILE] as $file) {
            if (file_exists($this->getFullPath($file))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> uncompressed => compressed
     */
    public function getAllHashes(): array
    {
        $file = $this->getFullPath(self::HASHES_FILE);
        if (!file_exists($file)) {
            return [];
        }

        return json_decode(file_get_contents($file), true) ?: [];
    }

    /**
     * Renames all data files so they're no longer picked up, used after importing them into a different storage.
     */
    public function archiveData(): void
    {
        // the login is not kept around, it's stored (encrypted) in the new storage
        if (file_exists($authFile = $this->getFullPath(self::AUTH_FILE))) {
            unlink($authFile);
        }
        foreach ([self::GAME_FILE, self::SETTINGS_FILE, self::HASHES_FILE, self::LIBRARY_FILE] as $file) {
            $path = $this->getFullPath($file);
            if (file_exists($path)) {
                rename($path, "{$path}.imported");
            }
        }
    }

    private function serialize(mixed $itemToSerialize): string
    {
        return serialize($this->normalize($itemToSerialize));
    }

    /**
     * @template T of mixed
     *
     * @param T $itemToNormalize
     * @return T
     */
    private function normalize(mixed $itemToNormalize): mixed
    {
        if (is_array($itemToNormalize)) {
            foreach ($itemToNormalize as $key => $item) {
                $itemToNormalize[$key] = $this->normalize($item);
            }

            return $itemToNormalize;
        } else if (is_object($itemToNormalize)) {
            $reflection = new ReflectionObject($itemToNormalize);
            $copy = $reflection->newInstanceWithoutConstructor();
            foreach ($reflection->getProperties() as $property) {
                try {
                    $currentValue = $property->getValue($itemToNormalize);
                    if ($currentValue instanceof Stringable) {
                        $currentValue = (string) $currentValue;
                    } else if (is_array($currentValue)) {
                        $currentValue = $this->normalize($currentValue);
                    }
                    $property->setValue($copy, $currentValue);
                } catch (Error) {
                    continue;
                }
            }

            return $copy;
        }

        return $itemToNormalize;
    }
}
