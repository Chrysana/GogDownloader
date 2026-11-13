<?php

namespace App\Service;

use App\DTO\DownloadableItem;
use App\DTO\GameDetail;
use App\DTO\GameExtra;
use App\DTO\GameInfo;
use App\DTO\LibraryItem;
use App\DTO\MovieInfo;
use App\DTO\OwnedItemInfo;
use App\DTO\SearchFilter;
use App\DTO\Url;
use App\Enum\Language;
use App\Enum\MediaType;
use App\Enum\OperatingSystem;
use App\Exception\AuthorizationException;
use App\Service\Persistence\PersistenceManager;
use DateInterval;
use Exception;
use JsonException;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionException;
use ReflectionProperty;
use SimpleXMLElement;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final class OwnedItemsManager
{
    private const GOG_ACCOUNT_URL = 'https://embed.gog.com/account';

    private const GOG_API_URL = 'https://api.gog.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AuthenticationManager $authorization,
        private readonly Serializer $serializer,
        private readonly PersistenceManager $persistence,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param MediaType    $mediaType
     * @param SearchFilter $filter
     * @param int|null     $productsCount
     *
     * @throws ClientExceptionInterface
     * @throws JsonException
     * @throws RedirectionExceptionInterface
     * @throws ReflectionException
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     *
     * @return iterable<OwnedItemInfo>
     */
    public function getOwnedItems(
        MediaType $mediaType,
        SearchFilter $filter = new SearchFilter(),
        ?int &$productsCount = null,
        int $httpTimeout = 3,
    ): iterable {
        $page = 1;
        $query = [
            'mediaType' => $mediaType->value,
            'page' => $page,
            'sortBy' => 'title',
            'hiddenFlag' => 0,
        ];
        if ($filter->languages !== null) {
            $query['language'] = implode(',', array_map(
                fn (Language $language): string => $language->value,
                $filter->languages,
            ));
        }
        if ($filter->operatingSystems !== null) {
            $query['system'] = implode(',', array_map(
                fn (OperatingSystem $operatingSystem): string => $operatingSystem->value,
                $filter->operatingSystems,
            ));
        }
        if ($filter->search !== null) {
            $query['search'] = $filter->search;
        }
        if ($filter->includeHidden) {
            unset($query['hiddenFlag']);
        }

        do {
            $response = $this->httpClient->request(
                Request::METHOD_GET,
                new Url(
                    host: self::GOG_ACCOUNT_URL,
                    path: 'getFilteredProducts',
                    query: $query,
                ),
                [
                    'auth_bearer' => (string) $this->authorization->getAuthorization(),
                    'timeout' => $httpTimeout,
                ]
            );

            try {
                $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
                ++$query['page'];
                $productsCount = $data['totalProducts'];
            } catch (JsonException) {
                throw new AuthorizationException('Failed to get list of games, you were probably logged out.');
            }

            foreach ($data['products'] as $product) {
                if ($product['isGame']) {
                    yield $this->serializer->deserialize($product, GameInfo::class);
                }
                if ($product['isMovie']) {
                    yield $this->serializer->deserialize($product, MovieInfo::class);
                }
            }
        } while ($query['page'] <= $data['totalPages']);
    }

    /**
     * Fetches the lightweight listing of the whole library (including hidden games) together with
     * hidden status and user defined tags, and stores it locally.
     *
     * @return array<int, LibraryItem>
     */
    public function refreshLibrary(MediaType $mediaType = MediaType::Game, int $httpTimeout = 3): array
    {
        $tagNames = [];
        $lists = $this->getRawProductLists([
            'all' => ['mediaType' => $mediaType->value],
            'visible' => ['mediaType' => $mediaType->value, 'hiddenFlag' => 0],
        ], $httpTimeout, $tagNames);

        $all = [];
        foreach ($lists['all'] as $product) {
            $all[(int) $product['id']] = $product;
        }
        $visibleIds = [];
        foreach ($lists['visible'] as $product) {
            $visibleIds[(int) $product['id']] = true;
        }

        $result = [];
        foreach ($all as $id => $product) {
            $tags = [];
            foreach ($product['tags'] ?? [] as $tag) {
                // tags are usually referenced by id, but be lenient in case GOG returns full objects
                $tagId = is_array($tag) ? (string) ($tag['id'] ?? '') : (string) $tag;
                $name = is_array($tag) && isset($tag['name']) ? $tag['name'] : ($tagNames[$tagId] ?? null);
                if ($name !== null && $name !== '') {
                    $tags[] = $name;
                }
            }

            $result[$id] = new LibraryItem(
                id: $id,
                title: $product['title'] ?? (string) $id,
                slug: $product['slug'] ?? '',
                hidden: isset($product['isHidden']) ? (bool) $product['isHidden'] : !isset($visibleIds[$id]),
                tags: array_values(array_unique($tags)),
            );
        }

        uasort($result, fn (LibraryItem $a, LibraryItem $b) => strcasecmp($a->title, $b->title));
        $this->persistence->storeLibrary($result);

        return $result;
    }

    /**
     * @return array<int, LibraryItem>
     */
    public function getLibrary(): array
    {
        return $this->persistence->getLibrary();
    }

    /**
     * @return list<string> all tag names used in the library
     */
    public function getTagNames(): array
    {
        $tags = [];
        foreach ($this->getLibrary() as $item) {
            foreach ($item->tags as $tag) {
                $tags[mb_strtolower($tag)] ??= $tag;
            }
        }
        natcasesort($tags);

        return array_values($tags);
    }

    public function getGameDetailByTitle(string $title): ?GameDetail
    {
        return array_find(
            $this->getLocalGameData(),
            fn ($gameDetail) => $gameDetail->title === $title,
        );
    }

    public function getItemDetail(OwnedItemInfo $item, int $httpTimeout = 3, bool $cached = true): ?GameDetail
    {
        $cacheItem = $cached ? $this->cache->getItem("game_detail.{$item->getType()->value}.{$item->getId()}") : null;
        if ($cacheItem?->isHit()) {
            return $cacheItem->get();
        }

        $detail = match ($item->getType()) {
            MediaType::Game => $this->getGameDetail($item, $httpTimeout),
            MediaType::Movie => $this->getMovieDetail($item, $httpTimeout),
        };

        $cacheItem?->set($detail);
        $cacheItem?->expiresAfter(new DateInterval('PT20M'));
        if ($cacheItem) {
            $this->cache->save($cacheItem);
        }

        return $detail;
    }

    /**
     * @param array<GameDetail> $details
     */
    public function storeGamesData(array $details): void
    {
        $this->persistence->storeLocalGameData($details);
    }

    public function storeSingleGameData(GameDetail $detail): void
    {
        $this->persistence->storeSingleGameDetail($detail);
    }

    /**
     * @return array<GameDetail>
     */
    public function getLocalGameData(): array
    {
        return $this->persistence->getLocalGameData() ?? [];
    }

    /**
     * @todo move this to deserialization (duplicate requests)
     */
    public function getChecksum(DownloadableItem $download, ?GameDetail $game, int $httpTimeout = 3): ?string
    {
        $parts = explode('/', $download->url);
        $id = $parts[array_key_last($parts)];
        $gameId = $download->gogGameId ?? $game?->id;

        $response = $this->httpClient->request(
            Request::METHOD_GET,
            new Url(
                host: self::GOG_API_URL,
                path: "products/{$gameId}",
                query: [
                    'expand' => 'downloads',
                ]
            ),
            [
                'auth_bearer' => (string) $this->authorization->getAuthorization(),
                'timeout' => $httpTimeout,
            ]
        );

        $data = json_decode($response->getContent(), true)['downloads'];
        foreach ($data as $items) {
            $targetUrl = array_map(
                fn (array $item) => $item['files'],
                $items,
            );
            $targetUrl = array_merge(...$targetUrl);
            $targetUrl = array_filter($targetUrl, fn (array $item) => str_ends_with($item['downlink'], $id));
            $targetUrl = array_values($targetUrl)[0]['downlink'] ?? null;
            if ($targetUrl === null) {
                continue;
            }

            $response = $this->httpClient->request(
                Request::METHOD_GET,
                $targetUrl,
                [
                    'auth_bearer' => (string) $this->authorization->getAuthorization(),
                    'timeout' => $httpTimeout,
                ]
            );
            $response = json_decode($response->getContent(), true);

            $response = $this->httpClient->request(
                Request::METHOD_GET,
                $response['checksum'],
                [
                    'timeout' => $httpTimeout,
                ]
            );

            try {
                $response = new SimpleXMLElement($response->getContent());
            } catch (ClientExceptionInterface | TransportExceptionInterface | ServerExceptionInterface) {
                return null;
            } catch (Exception $e) {
                if (!str_contains($e->getMessage(), 'String could not be parsed as XML')) {
                    throw $e;
                }

                return null;
            }

            return (string) $response['md5'];
        }

        return null;
    }

    /**
     * Fetches the details of several games at once. All requests of all the games run in parallel,
     * in three rounds: game information, download links and checksum files.
     *
     * @param list<OwnedItemInfo> $items games (movies are not supported)
     *
     * @return array<int, GameDetail|null|Throwable> by game id: the detail, null when the base product is missing,
     *                                               or the error that happened for that game
     */
    public function getGameDetails(array $items, int $httpTimeout = 3): array
    {
        $authorization = (string) $this->authorization->getAuthorization();
        $results = [];
        $states = [];

        // round 1: game information
        foreach ($items as $item) {
            $states[$item->getId()] = [
                'item' => $item,
                'owned' => $this->httpClient->request(
                    Request::METHOD_GET,
                    new Url(host: self::GOG_ACCOUNT_URL, path: "gameDetails/{$item->getId()}.json"),
                    ['auth_bearer' => $authorization, 'timeout' => $httpTimeout],
                ),
                'general' => $this->httpClient->request(
                    Request::METHOD_GET,
                    new Url(
                        host: self::GOG_API_URL,
                        path: "products/{$item->getId()}",
                        query: ['expand' => 'downloads,expanded_dlcs'],
                    ),
                    ['auth_bearer' => $authorization, 'timeout' => $httpTimeout],
                ),
            ];
        }
        foreach ($states as $id => $state) {
            try {
                $parsed = $this->parseGameDetail($state['item'], $state['owned'], $state['general']);
                if ($parsed === null) {
                    $results[$id] = null;
                    unset($states[$id]);
                    continue;
                }
                [$detail, $downlinks] = $parsed;
                $states[$id]['detail'] = $detail;
                $states[$id]['pending'] = $this->getPendingChecksums($detail, $downlinks, $httpTimeout);
            } catch (Throwable $e) {
                $state['owned']->cancel();
                $state['general']->cancel();
                $results[$id] = $e;
                unset($states[$id]);
            }
        }

        // round 2: download links
        foreach ($states as $id => $state) {
            $states[$id]['links'] = [];
            foreach ($state['pending'] as $index => [, $link]) {
                $states[$id]['links'][$index] = $this->httpClient->request(Request::METHOD_GET, $link, [
                    'auth_bearer' => $authorization,
                    'timeout' => $httpTimeout,
                ]);
            }
        }
        foreach ($states as $id => $state) {
            try {
                [$states[$id]['checksumUrls'], $states[$id]['inaccessible']] = $this->readDownlinks($state['pending'], $state['links']);
            } catch (Throwable $e) {
                foreach ($state['links'] as $response) {
                    $response->cancel();
                }
                $results[$id] = $e;
                unset($states[$id]);
            }
        }

        // round 3: checksum files
        foreach ($states as $id => $state) {
            $states[$id]['checksums'] = [];
            foreach ($state['checksumUrls'] as $index => $url) {
                $states[$id]['checksums'][$index] = $this->httpClient->request(Request::METHOD_GET, $url, [
                    'timeout' => $httpTimeout,
                ]);
            }
        }
        foreach ($states as $id => $state) {
            try {
                $this->readChecksums($state['pending'], $state['checksums']);
                $results[$id] = $this->withoutExtras($state['detail'], $state['inaccessible']);
            } catch (Throwable $e) {
                $results[$id] = $e;
            }
        }

        // keep the order of the input
        $ordered = [];
        foreach ($items as $item) {
            $ordered[$item->getId()] = $results[$item->getId()];
        }

        return $ordered;
    }

    /**
     * Fetches several product listings at once: the first page of every listing in parallel, then all the
     * remaining pages in parallel.
     *
     * @param array<string, array<string, mixed>> $queries
     * @param array<string, string>               $tagNames filled with tag id => tag name found in the responses
     *
     * @return array<string, list<array<string, mixed>>> products of each listing, in page order
     */
    private function getRawProductLists(array $queries, int $httpTimeout, array &$tagNames): array
    {
        $authorization = (string) $this->authorization->getAuthorization();
        $request = fn (array $query, int $page) => $this->httpClient->request(
            Request::METHOD_GET,
            new Url(
                host: self::GOG_ACCOUNT_URL,
                path: 'getFilteredProducts',
                query: [...$query, 'page' => $page, 'sortBy' => 'title'],
            ),
            [
                'auth_bearer' => $authorization,
                'timeout' => $httpTimeout,
            ],
        );
        $read = function (ResponseInterface $response) use (&$tagNames): array {
            try {
                $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new AuthorizationException('Failed to get list of games, you were probably logged out.');
            }
            foreach ($data['tags'] ?? [] as $tag) {
                if (isset($tag['id'], $tag['name'])) {
                    $tagNames[(string) $tag['id']] = $tag['name'];
                }
            }

            return $data;
        };

        $firstPages = array_map(fn (array $query) => $request($query, 1), $queries);
        $pages = [];
        $remaining = [];
        foreach ($firstPages as $key => $response) {
            $data = $read($response);
            $pages[$key][1] = $data['products'] ?? [];
            for ($page = 2; $page <= ($data['totalPages'] ?? 0); ++$page) {
                $remaining[$key][$page] = $request($queries[$key], $page);
            }
        }
        foreach ($remaining as $key => $responses) {
            foreach ($responses as $page => $response) {
                $pages[$key][$page] = $read($response)['products'] ?? [];
            }
        }

        $result = [];
        foreach ($queries as $key => $query) {
            ksort($pages[$key]);
            $result[$key] = array_merge(...array_values($pages[$key]));
        }

        return $result;
    }

    private function getGameDetail(OwnedItemInfo $item, int $httpTimeout): ?GameDetail
    {
        $result = $this->getGameDetails([$item], $httpTimeout)[$item->getId()];
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /**
     * @return array{GameDetail, array<int, list<list<string>>>}|null the detail and the downlinks of its products
     */
    private function parseGameDetail(OwnedItemInfo $item, ResponseInterface $ownedResponse, ResponseInterface $generalResponse): ?array
    {
        $ownedContent = json_decode($ownedResponse->getContent(), true);
        if (isset($ownedContent['isBaseProductMissing']) && $ownedContent['isBaseProductMissing']) {
            $generalResponse->cancel();

            return null;
        }
        $ownedDlcTitles = array_map(
            fn (array $item) => $item['title'],
            $ownedContent['dlcs'],
        );
        $ownedExtrasTitles = array_map(
            fn (array $item) => $item['name'],
            $ownedContent['extras'],
        );

        $generalContent = json_decode($generalResponse->getContent(), true);
        $downlinks = $this->collectDownlinks($item->getId(), $generalContent);
        $generalContent['expanded_dlcs'] = array_filter(
            $generalContent['expanded_dlcs'] ?? [],
            fn (array $item) => in_array($item['title'], $ownedDlcTitles, true),
        );
        $generalContent['downloads']['bonus_content'] = array_filter(
            $generalContent['downloads']['bonus_content'] ?? [],
            fn (array $item) => in_array($item['name'], $ownedExtrasTitles, true),
        );

        $detail = $this->serializer->deserialize($generalContent, GameDetail::class, [
            'id' => $item->getId(),
            'slug' => $item->getSlug(),
        ]);
        assert($detail instanceof GameDetail);

        return [$detail, $downlinks];
    }

    /**
     * Maps each product (the game and its DLCs) to the downlinks of its files, grouped by category
     * (installers, patches, ...), in the order the API returns them.
     *
     * @return array<int, list<list<string>>>
     */
    private function collectDownlinks(int $gameId, array $productContent): array
    {
        $collect = function (array $downloads): array {
            $categories = [];
            foreach ($downloads as $items) {
                if (!is_array($items)) {
                    continue;
                }
                $links = [];
                foreach ($items as $downloadItem) {
                    foreach ($downloadItem['files'] ?? [] as $file) {
                        if (isset($file['downlink'])) {
                            $links[] = $file['downlink'];
                        }
                    }
                }
                $categories[] = $links;
            }

            return $categories;
        };

        $result = [$gameId => $collect($productContent['downloads'] ?? [])];
        foreach ($productContent['expanded_dlcs'] ?? [] as $dlc) {
            if (isset($dlc['id'])) {
                $result[(int) $dlc['id']] = $collect($dlc['downloads'] ?? []);
            }
        }

        return $result;
    }

    /**
     * @param array<int, list<list<string>>> $downlinks see collectDownlinks()
     *
     * @return list<array{DownloadableItem, string}> files that need a checksum, with their downlink
     */
    private function getPendingChecksums(GameDetail $detail, array $downlinks, int $httpTimeout): array
    {
        $pending = [];
        foreach ([...$detail->downloads, ...$detail->extras] as $download) {
            if ($download->md5 !== null) {
                continue;
            }
            if ($download instanceof GameExtra) {
                // the url of an extra is its downlink already
                $pending[] = [$download, $download->url];

                continue;
            }

            $parts = explode('/', $download->url);
            $id = $parts[array_key_last($parts)];
            $productId = $download->gogGameId ?? $detail->id;
            $link = null;
            foreach ($downlinks[$productId] ?? [] as $category) {
                foreach ($category as $candidate) {
                    if (str_ends_with($candidate, $id)) {
                        $link = $candidate;
                        break 2;
                    }
                }
            }

            if ($link === null) {
                if (!isset($downlinks[$productId])) {
                    // the product wasn't part of the response (shouldn't happen), use the slow path
                    $this->setMd5($download, $detail, $httpTimeout);
                } else {
                    $this->setMd5Value($download, '');
                }
                continue;
            }
            $pending[] = [$download, $link];
        }

        return $pending;
    }

    /**
     * @param list<array{DownloadableItem, string}> $pending
     * @param array<int, ResponseInterface>         $responses
     *
     * @return array{array<int, string>, array<int, true>} checksum urls by pending index, and the inaccessible extras
     */
    private function readDownlinks(array $pending, array $responses): array
    {
        $checksumUrls = [];
        $inaccessible = [];
        foreach ($responses as $index => $response) {
            [$download] = $pending[$index];

            try {
                $data = json_decode($response->getContent(), true);
            } catch (ClientExceptionInterface $e) {
                $code = $e->getResponse()->getStatusCode();
                // extras that are not accessible are left out, like before
                if ($download instanceof GameExtra && in_array($code, [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND], true)) {
                    $inaccessible[spl_object_id($download)] = true;
                    continue;
                }
                throw $e;
            }
            if (!is_array($data) || !isset($data['checksum']) || !is_string($data['checksum'])) {
                $this->setMd5Value($download, '');
                continue;
            }
            $checksumUrls[$index] = $data['checksum'];
        }

        return [$checksumUrls, $inaccessible];
    }

    /**
     * @param list<array{DownloadableItem, string}> $pending
     * @param array<int, ResponseInterface>         $responses
     */
    private function readChecksums(array $pending, array $responses): void
    {
        foreach ($responses as $index => $response) {
            [$download] = $pending[$index];
            $md5 = '';

            try {
                $xml = new SimpleXMLElement($response->getContent());
                $md5 = (string) $xml['md5'];
            } catch (ClientExceptionInterface|TransportExceptionInterface|ServerExceptionInterface) {
                // no checksum available
            } catch (Exception $e) {
                if (!str_contains($e->getMessage(), 'String could not be parsed as XML')) {
                    throw $e;
                }
            }
            $this->setMd5Value($download, $md5);
        }
    }

    /**
     * @param array<int, true> $inaccessible object ids of extras to remove
     */
    private function withoutExtras(GameDetail $detail, array $inaccessible): GameDetail
    {
        if (!count($inaccessible)) {
            return $detail;
        }

        return new GameDetail(
            id: $detail->id,
            title: $detail->title,
            cdKey: $detail->cdKey,
            downloads: $detail->downloads,
            slug: $detail->slug,
            extras: array_values(array_filter(
                $detail->extras,
                fn (GameExtra $extra) => !isset($inaccessible[spl_object_id($extra)]),
            )),
        );
    }

    private function setMd5Value(DownloadableItem $download, string $md5): void
    {
        new ReflectionProperty($download, 'md5')->setValue($download, $md5);
    }

    private function getMovieDetail(OwnedItemInfo $item, int $httpTimeout): GameDetail
    {
        $response = $this->httpClient->request(
            Request::METHOD_GET,
            new Url(
                host: self::GOG_ACCOUNT_URL,
                path: "movieDetails/{$item->id}.json",
            ),
            [
                'auth_bearer' => (string) $this->authorization->getAuthorization(),
                'timeout' => $httpTimeout,
            ]
        );

        $detail = $this->serializer->deserialize($response->getContent(), GameDetail::class, [
            'id' => $item->getId(),
        ]);
        assert($detail instanceof GameDetail);

        return $detail;
    }

    private function setMd5(DownloadableItem $download, GameDetail $game, int $httpTimeout): void
    {
        if ($download->md5 !== null) {
            return;
        }
        $md5 = $this->getChecksum($download, $game, $httpTimeout);
        if ($md5 === null) {
            $md5 = '';
        }
        $reflection = new ReflectionProperty($download, 'md5');
        $reflection->setValue($download, $md5);
    }
}
