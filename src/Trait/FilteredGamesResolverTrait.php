<?php

namespace App\Trait;

use App\DTO\GameInstaller;
use App\DTO\GameDetail;
use App\DTO\OwnedItemInfo;
use App\DTO\SearchFilter;
use App\Enum\Language;
use App\Enum\MediaType;
use App\Enum\OperatingSystem;
use App\Exception\InvalidValueException;
use App\Service\OwnedItemsManager;
use Rikudou\Iterables\Iterables;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ValueError;

trait FilteredGamesResolverTrait
{
    use EnumExceptionParserTrait;

    /**
     * @return array<Language>
     * @throws InvalidValueException
     */
    private function getLanguages(InputInterface $input): array
    {
        try {
            return array_map(
                fn (string $langCode) => Language::from($langCode),
                $input->getOption('language'),
            );
        } catch (ValueError $e) {
            $invalid = $this->getInvalidOption($e);
            throw new InvalidValueException(
                $invalid
                    ? 'Some of the languages you provided are not valid'
                    : "The language '{$invalid}' is not a supported language'"
            );
        }
    }

    /**
     * @return array<OperatingSystem>
     * @throws InvalidValueException
     */
    private function getOperatingSystems(InputInterface $input): array
    {
        try {
            return array_map(
                fn(string $operatingSystem) => OperatingSystem::from($operatingSystem),
                $input->getOption('os'),
            );
        } catch (ValueError $e) {
            $invalid = $this->getInvalidOption($e);
            throw new InvalidValueException(
                $invalid
                    ? 'Some of the operating systems you provided are not valid'
                    : "The operating system '{$invalid}' is not a valid operating system");
        }
    }

    /**
     * @return array<int, true>|null game ids from the file given in --id-file, or null if not specified
     * @throws InvalidValueException
     */
    private function getIdFileIds(InputInterface $input): ?array
    {
        $file = $input->hasOption('id-file') ? $input->getOption('id-file') : null;
        if (!$file) {
            return null;
        }
        if (!is_readable($file)) {
            throw new InvalidValueException("The id file '{$file}' is not readable.");
        }

        $ids = [];
        foreach (preg_split('/\s+/', (string) file_get_contents($file), flags: PREG_SPLIT_NO_EMPTY) as $id) {
            if (ctype_digit($id)) {
                $ids[(int) $id] = true;
            }
        }

        return $ids;
    }

    /**
     * Builds a filter based on library metadata (hidden status, tags), or null if no such filter was requested.
     *
     * @return (callable(int $gameId): bool)|null
     */
    private function getLibraryFilter(
        InputInterface $input,
        OutputInterface $output,
        OwnedItemsManager $ownedItemsManager,
        bool $refresh = false,
    ): ?callable {
        $skipHidden = $input->hasOption('skip-hidden') && $input->getOption('skip-hidden');
        $tags = $input->hasOption('tag') ? $input->getOption('tag') : [];
        $withoutTags = $input->hasOption('without-tag') ? $input->getOption('without-tag') : [];

        if (!$skipHidden && !$tags && !$withoutTags) {
            return null;
        }

        $library = $refresh ? [] : $ownedItemsManager->getLibrary();
        if (!count($library)) {
            if ($output->isVerbose()) {
                $output->writeln('Fetching library information (hidden games and tags)...');
            }
            $library = $ownedItemsManager->refreshLibrary(
                httpTimeout: (int) ($input->hasOption('idle-timeout') ? $input->getOption('idle-timeout') : 3),
            );
        }

        if ($tags) {
            $knownTags = array_map(mb_strtolower(...), $ownedItemsManager->getTagNames());
            foreach ($tags as $tag) {
                if (!in_array(mb_strtolower($tag), $knownTags, true)) {
                    throw new InvalidValueException("The tag '{$tag}' does not exist in your library. Run the 'tags' command to see the available tags, or 'update-database' if you've created it recently.");
                }
            }
        }

        return function (int $gameId) use ($library, $skipHidden, $tags, $withoutTags): bool {
            $item = $library[$gameId] ?? null;

            if ($skipHidden && $item?->hidden) {
                return false;
            }
            if ($tags && !array_any($tags, fn (string $tag) => (bool) $item?->hasTag($tag))) {
                return false;
            }
            if ($withoutTags && $item && array_any($withoutTags, fn (string $tag) => $item->hasTag($tag))) {
                return false;
            }

            return true;
        };
    }

    /**
     * @return iterable<GameDetail>
     * @throws InvalidValueException
     */
    private function getGames(InputInterface $input, OutputInterface $output, OwnedItemsManager $ownedItemsManager): iterable
    {
        $operatingSystems = $this->getOperatingSystems($input);
        $languages = $this->getLanguages($input);

        $englishFallback = $input->getOption('language-fallback-english');
        $excludeLanguage = Language::tryFrom($input->getOption('exclude-game-with-language') ?? '');
        $timeout = $input->getOption('idle-timeout');
        $only = $input->getOption('only');
        $without = $input->getOption('without');

        $filter = new SearchFilter(
            operatingSystems: $operatingSystems,
            languages: $languages,
        );

        $iterable = $input->getOption('update')
            ? Iterables::filter(Iterables::map(
                function (OwnedItemInfo $info) use ($ownedItemsManager, $timeout, $output): GameDetail {
                    if ($output->isVerbose()) {
                        $output->writeln("Updating metadata for {$info->getTitle()}...");
                    }

                    return $ownedItemsManager->getItemDetail($info, $timeout);
                },
                $ownedItemsManager->getOwnedItems(MediaType::Game, $filter, httpTimeout: $timeout),
            ))
            : $ownedItemsManager->getLocalGameData();

        if ($only) {
            $iterable = Iterables::filter($iterable, fn (GameDetail $detail) => in_array(
                strtolower($detail->title),
                array_map(fn (string $title) => strtolower($title), $only),
                true,
            ));
        }
        if ($without) {
            $iterable = Iterables::filter($iterable, fn (GameDetail $detail) => !in_array(
                strtolower($detail->title),
                array_map(fn (string $title) => strtolower($title), $without),
                true,
            ));
        }

        if (($ids = $this->getIdFileIds($input)) !== null) {
            $iterable = Iterables::filter($iterable, fn (GameDetail $detail) => isset($ids[$detail->id]));
        }

        if ($libraryFilter = $this->getLibraryFilter($input, $output, $ownedItemsManager, refresh: (bool) $input->getOption('update'))) {
            $iterable = Iterables::filter($iterable, fn (GameDetail $detail) => $libraryFilter($detail->id));
        }

        if ($excludeLanguage) {
            $iterable = Iterables::filter(
                $iterable,
                fn (GameDetail $detail) => array_find(
                    $detail->downloads,
                    fn (GameInstaller $download)
                        => $download->language === $excludeLanguage->value || $download->language === $excludeLanguage->getLocalName(),
                ) === null,
            );
        }

        if ($englishFallback || $languages) {
            $iterable = Iterables::map(
                function (GameDetail $game) use ($englishFallback, $languages) {
                    $languageNames = array_map(
                        fn (Language $language) => $language->getLocalName(),
                        $languages,
                    );
                    $languageCodes = array_map(
                        fn (Language $language) => $language->value,
                        $languages,
                    );

                    $downloads = array_filter(
                        $game->downloads,
                        fn (GameInstaller $download)
                            => in_array($download->language, $languageNames, true) || in_array($download->language, $languageCodes, true),
                    );
                    if (!count($downloads) && $englishFallback) {
                        $downloads = array_filter(
                            $game->downloads,
                            fn (GameInstaller $download)
                                => $download->language === Language::English->value || $download->language === Language::English->getLocalName(),
                        );
                    }

                    return new GameDetail(
                        id: $game->id,
                        title: $game->title,
                        cdKey: $game->cdKey,
                        downloads: $downloads,
                        slug: $game->slug,
                        extras: $game->extras,
                    );
                },
                $iterable,
            );
        }

        if ($operatingSystems) {
            $iterable = Iterables::map(
                function (GameDetail $game) use ($operatingSystems) {
                    $downloads = array_filter(
                        $game->downloads,
                        fn (GameInstaller $download) => in_array($download->platform, array_map(
                            fn (OperatingSystem $system) => $system->value,
                            $operatingSystems,
                        ))
                    );

                    return new GameDetail(
                        id: $game->id,
                        title: $game->title,
                        cdKey: $game->cdKey,
                        downloads: $downloads,
                        slug: $game->slug,
                        extras: $game->extras,
                    );
                },
                $iterable,
            );
        }

        return Iterables::filter(
            $iterable,
            fn (GameDetail $gameDetail) => count($gameDetail->downloads) > 0,
        );
    }
}
