<?php

namespace App\Service\Factory;

use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpClientFactory
{
    /**
     * Same as HttpClient::create(), except that curl is also used on Windows when it verifies certificates with
     * Schannel (the Windows certificate store), as it does in the self-contained executable.
     *
     * HttpClient::create() only uses curl on Windows when a CA file is configured in php.ini; without one it falls
     * back to the much slower NativeHttpClient, which made updating the database about ten times slower.
     *
     * @param array<string, mixed> $defaultOptions
     */
    public static function create(array $defaultOptions = [], int $maxHostConnections = 6, int $maxPendingPushes = 50): HttpClientInterface
    {
        if (self::curlUsesSchannel()) {
            return new CurlHttpClient($defaultOptions, $maxHostConnections, $maxPendingPushes);
        }

        return HttpClient::create($defaultOptions, $maxHostConnections, $maxPendingPushes);
    }

    private static function curlUsesSchannel(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || !extension_loaded('curl')) {
            return false;
        }
        $version = curl_version();

        return is_array($version) && str_starts_with((string) ($version['ssl_version'] ?? ''), 'Schannel');
    }
}
