<?php

namespace App\Service;

use FFI;
use RuntimeException;
use Throwable;

/**
 * Encrypts secrets (the GOG access and refresh tokens) before they're stored on disk.
 *
 * - Windows: DPAPI (CryptProtectData) - the data can only be decrypted by the same Windows user, no key file needed.
 * - Elsewhere: libsodium secretbox with a random key stored in the user's config directory (outside the data
 *   directory), so a copied/shared database alone doesn't reveal the tokens. The key location can be changed using
 *   the GOG_DOWNLOADER_SECRET_KEY_FILE environment variable (used by the docker image).
 * - If neither is available the value is stored as is.
 *
 * Values that were stored unencrypted by older versions are returned unchanged, so they keep working
 * and get encrypted the next time they're saved.
 */
final class SecretProtector
{
    private const PREFIX = 'enc:';

    private const DPAPI = 'dpapi';

    private const SODIUM = 'sodium';

    private const ENTROPY = 'gog-downloader/secret/v1';

    private const CRYPTPROTECT_UI_FORBIDDEN = 0x1;

    private ?string $method = null;

    private ?FFI $crypt32 = null;

    private ?FFI $kernel32 = null;

    public function getMethod(): string
    {
        if ($this->method !== null) {
            return $this->method;
        }

        if (PHP_OS_FAMILY === 'Windows' && $this->initDpapi()) {
            return $this->method = self::DPAPI;
        }
        if (function_exists('sodium_crypto_secretbox')) {
            return $this->method = self::SODIUM;
        }

        return $this->method = 'none';
    }

    public function describe(): string
    {
        return match ($this->getMethod()) {
            self::DPAPI => 'encrypted (Windows DPAPI, tied to your Windows account)',
            self::SODIUM => 'encrypted (libsodium, key: ' . $this->getKeyFile() . ')',
            default => 'NOT encrypted (neither FFI nor sodium is available)',
        };
    }

    public function protect(string $value): string
    {
        try {
            return match ($this->getMethod()) {
                self::DPAPI => self::PREFIX . self::DPAPI . ':' . base64_encode($this->dpapiProtect($value)),
                self::SODIUM => self::PREFIX . self::SODIUM . ':' . base64_encode($this->sodiumProtect($value)),
                default => $value,
            };
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to encrypt the login data: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @return string|null the decrypted value, or null if it cannot be decrypted (e.g. different user or missing key)
     */
    public function unprotect(string $value): ?string
    {
        if (!str_starts_with($value, self::PREFIX)) {
            return $value; // stored by an older version without encryption
        }

        $parts = explode(':', $value, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [, $method, $payload] = $parts;
        $payload = base64_decode($payload, true);
        if ($payload === false) {
            return null;
        }

        try {
            return match ($method) {
                self::DPAPI => PHP_OS_FAMILY === 'Windows' && $this->initDpapi() ? $this->dpapiUnprotect($payload) : null,
                self::SODIUM => function_exists('sodium_crypto_secretbox_open') ? $this->sodiumUnprotect($payload) : null,
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    public function isProtected(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    // ---- DPAPI ------------------------------------------------------------------------------------------------

    private function initDpapi(): bool
    {
        if ($this->crypt32 !== null) {
            return true;
        }
        if (!extension_loaded('ffi')) {
            return false;
        }

        try {
            $this->crypt32 = FFI::cdef(<<<'C'
                typedef struct { unsigned long cbData; unsigned char* pbData; } DATA_BLOB;
                int CryptProtectData(DATA_BLOB* pDataIn, const unsigned short* szDataDescr, DATA_BLOB* pOptionalEntropy, void* pvReserved, void* pPromptStruct, unsigned long dwFlags, DATA_BLOB* pDataOut);
                int CryptUnprotectData(DATA_BLOB* pDataIn, unsigned short** ppszDataDescr, DATA_BLOB* pOptionalEntropy, void* pvReserved, void* pPromptStruct, unsigned long dwFlags, DATA_BLOB* pDataOut);
                C, 'crypt32.dll');
            $this->kernel32 = FFI::cdef('void* LocalFree(void* hMem);', 'kernel32.dll');
        } catch (Throwable) {
            $this->crypt32 = null;
            $this->kernel32 = null;

            return false;
        }

        return true;
    }

    private function dpapiProtect(string $data): string
    {
        return $this->dpapiCall('CryptProtectData', $data);
    }

    private function dpapiUnprotect(string $data): ?string
    {
        return $this->dpapiCall('CryptUnprotectData', $data);
    }

    private function dpapiCall(string $function, string $data): ?string
    {
        $ffi = $this->crypt32;
        assert($ffi !== null && $this->kernel32 !== null);

        [$input, $inputBuffer] = $this->createBlob($ffi, $data);
        [$entropy, $entropyBuffer] = $this->createBlob($ffi, self::ENTROPY);
        $output = $ffi->new('DATA_BLOB');

        $result = $ffi->{$function}(
            FFI::addr($input),
            null,
            FFI::addr($entropy),
            null,
            null,
            self::CRYPTPROTECT_UI_FORBIDDEN,
            FFI::addr($output),
        );
        unset($inputBuffer, $entropyBuffer);

        if (!$result) {
            if ($function === 'CryptUnprotectData') {
                return null;
            }
            throw new RuntimeException("{$function} failed");
        }

        $value = FFI::string($output->pbData, $output->cbData);
        $this->kernel32->LocalFree($output->pbData);

        return $value;
    }

    /**
     * @return array{FFI\CData, FFI\CData} the blob and its buffer (the buffer must stay alive while the blob is used)
     */
    private function createBlob(FFI $ffi, string $data): array
    {
        $length = strlen($data);
        $buffer = $ffi->new('unsigned char[' . max(1, $length) . ']');
        if ($length > 0) {
            FFI::memcpy($buffer, $data, $length);
        }
        $blob = $ffi->new('DATA_BLOB');
        $blob->cbData = $length;
        $blob->pbData = $ffi->cast('unsigned char*', FFI::addr($buffer[0]));

        return [$blob, $buffer];
    }

    // ---- sodium -----------------------------------------------------------------------------------------------

    private function sodiumProtect(string $data): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($data, $nonce, $this->getSodiumKey(create: true));
    }

    private function sodiumUnprotect(string $data): ?string
    {
        $key = $this->getSodiumKey(create: false);
        if ($key === null || strlen($data) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $result = sodium_crypto_secretbox_open(substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);

        return $result === false ? null : $result;
    }

    private function getSodiumKey(bool $create): ?string
    {
        $file = $this->getKeyFile();
        if (is_file($file)) {
            $key = base64_decode(trim((string) file_get_contents($file)), true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        }
        if (!$create) {
            return null;
        }

        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the directory for the encryption key: {$directory}");
        }
        $key = sodium_crypto_secretbox_keygen();
        $previousUmask = umask(0077);

        try {
            if (file_put_contents($file, base64_encode($key)) === false) {
                throw new RuntimeException("Cannot write the encryption key to {$file}");
            }
        } finally {
            umask($previousUmask);
        }
        @chmod($file, 0600);

        return $key;
    }

    private function getKeyFile(): string
    {
        $configured = $_ENV['GOG_DOWNLOADER_SECRET_KEY_FILE'] ?? getenv('GOG_DOWNLOADER_SECRET_KEY_FILE');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: null;
        $base = match (true) {
            PHP_OS_FAMILY === 'Windows' => getenv('APPDATA') ?: ($home ? "{$home}/AppData/Roaming" : null),
            PHP_OS_FAMILY === 'Darwin' => $home ? "{$home}/Library/Application Support" : null,
            default => getenv('XDG_CONFIG_HOME') ?: ($home ? "{$home}/.config" : null),
        };
        if ($base === null) {
            // no home directory, fall back to the data directory
            return sprintf('%s/.gog-downloader-secret.key', $_ENV['CONFIG_DIRECTORY'] ?? getcwd());
        }

        return "{$base}/gog-downloader/secret.key";
    }
}
