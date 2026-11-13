<?php

namespace App\Command;

use App\DTO\GameDetail;
use App\DTO\OwnedItemInfo;
use App\DTO\SearchFilter;
use App\Enum\Language;
use App\Enum\MediaType;
use App\Enum\NamingConvention;
use App\Enum\OperatingSystem;
use App\Enum\Setting;
use App\Exception\InvalidValueException;
use App\Exception\TooManyRetriesException;
use App\Service\MachineProgress;
use App\Service\OwnedItemsManager;
use App\Service\Persistence\PersistenceManager;
use App\Service\RetryService;
use App\Trait\CommonOptionsTrait;
use App\Trait\FilteredGamesResolverTrait;
use App\Trait\MigrationCheckerTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use ValueError;

#[AsCommand('update-database')]
final class UpdateDatabaseCommand extends Command
{
    /** how many games are fetched at the same time */
    private const PARALLEL_GAMES = 6;

    use FilteredGamesResolverTrait; // includes EnumExceptionParserTrait
    use CommonOptionsTrait;
    use MigrationCheckerTrait;

    public function __construct(
        private readonly OwnedItemsManager $ownedItemsManager,
        private readonly RetryService      $retryService,
        private readonly PersistenceManager $persistence,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Updates the games/files database.')
            ->addOption(
                'new-only',
                null,
                InputOption::VALUE_NONE,
                'Download information only about new games',
            )
            ->addOption(
                'updated-only',
                'u',
                InputOption::VALUE_NONE,
                'Download information only about updated games',
            )
            ->addOption(
                'clear',
                'c',
                InputOption::VALUE_NONE,
                'Clear local database before updating it',
            )
            ->addOption(
                'search',
                's',
                InputOption::VALUE_REQUIRED,
                'Update only games that match the given search',
            )
            ->addOption(
                'os',
                'o',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by OS, allowed values are: ' . implode(
                    ', ',
                    array_map(
                        fn (OperatingSystem $os) => $os->value,
                        OperatingSystem::cases(),
                    )
                ),
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by language, for list of languages run "languages"'
            )
            ->addOption(
                'include-hidden',
                null,
                InputOption::VALUE_NONE,
                'Include hidden games in the update',
            )
            ->addOption(
                'retry',
                null,
                InputOption::VALUE_REQUIRED,
                'How many times should the download be retried in case of failure.',
                3,
            )
            ->addOption(
                'retry-delay',
                null,
                InputOption::VALUE_REQUIRED,
                'The delay in seconds between each retry.',
                1,
            )
            ->addOption(
                'skip-errors',
                null,
                InputOption::VALUE_NONE,
                "Skip games that for whatever reason couldn't be downloaded"
            )
            ->addOption(
                'idle-timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the idle timeout in seconds for http requests',
                3,
            )
            ->addOption(
                name: 'tag',
                shortcut: 't',
                mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                description: 'Only update games that have this tag in your GOG library. Can be specified multiple times. Case insensitive.',
            )
            ->addOption(
                name: 'without-tag',
                mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                description: 'Skip games that have this tag in your GOG library. Can be specified multiple times. Case insensitive.',
            )
            ->addOption(
                name: 'remove-hidden',
                mode: InputOption::VALUE_NONE,
                description: 'Remove games that are hidden in your GOG library from the local database (unless --include-hidden is specified).',
            )
            ->addOption(
                name: 'json-progress',
                mode: InputOption::VALUE_NONE,
                description: 'Emit machine readable progress events instead of progress bars (used by the interactive UI).',
            )
            ->addIdFileOption()
            ->setAliases(['update'])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->showInfoIfMigrationsAreNeeded($io, $this->persistence);

        if ($this->persistence->getSetting(Setting::NamingConvention) === NamingConvention::Custom->value) {
            $io->warning("You're using the deprecated custom naming convention for game directories. To migrate your game directory to the new naming convention, please use the command 'migrate-naming-scheme'.");
        }

        if ($input->getOption('clear')) {
            $this->ownedItemsManager->storeGamesData([]);
        }

        $timeout = $input->getOption('idle-timeout');
        $machine = $input->getOption('json-progress') ? new MachineProgress($output) : MachineProgress::disabled();

        // the library listing is cheap and keeps hidden status and tags up to date
        $library = $this->retryService->retry(
            fn () => $this->ownedItemsManager->refreshLibrary(httpTimeout: $timeout),
            $input->getOption('retry'),
            $input->getOption('retry-delay'),
        );

        if ($input->getOption('remove-hidden') && !$input->getOption('include-hidden')) {
            $stored = $this->ownedItemsManager->getLocalGameData();
            $kept = array_values(array_filter(
                $stored,
                fn (GameDetail $detail) => !($library[$detail->id]->hidden ?? false),
            ));
            if (count($kept) !== count($stored)) {
                $this->ownedItemsManager->storeGamesData($kept);
                $io->note(sprintf('Removed %d hidden game(s) from the local database', count($stored) - count($kept)));
            }
        }

        try {
            $libraryFilter = $this->getLibraryFilter($input, $output, $this->ownedItemsManager);
        } catch (InvalidValueException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $storedItems = $this->ownedItemsManager->getLocalGameData();
        $storedItemIds = array_map(fn (GameDetail $detail) => $detail->id, $storedItems);

        try {
            $languages = array_map(
                fn (string $langCode) => Language::from($langCode),
                $input->getOption('language'),
            );
        } catch (ValueError $e) {
            $invalid = $this->getInvalidOption($e);
            $io->error(
                $invalid
                    ? 'Some of the languages you provided are not valid'
                    : "The language '{$invalid}' is not a supported language'"
            );

            return Command::FAILURE;
        }

        try {
            $operatingSystems = array_map(
                fn (string $operatingSystem) => OperatingSystem::from($operatingSystem),
                $input->getOption('os'),
            );
        } catch (ValueError $e) {
            $invalid = $this->getInvalidOption($e);
            $io->error(
                $invalid
                    ? 'Some of the operating systems you provided are not valid'
                    : "The operating system '{$invalid}' is not a valid operating system"
            );

            return Command::FAILURE;
        }

        $filter = new SearchFilter(
            operatingSystems: $operatingSystems,
            languages: $languages,
            search: $input->getOption('search'),
            includeHidden: $input->getOption('include-hidden'),
        );

        foreach ($this->getTypes($input) as $type) {
            $items = $this->ownedItemsManager->getOwnedItems(
                mediaType: $type,
                filter: $filter,
                productsCount: $count,
                httpTimeout: $timeout,
            );
            if ($input->getOption('new-only')) {
                $items = array_filter([...$items], fn (OwnedItemInfo $item) => !in_array($item->getId(), $storedItemIds));
                $count = count($items);
            }
            if ($input->getOption('updated-only')) {
                $items = array_filter([...$items], function (OwnedItemInfo $item) use ($storedItemIds) {
                    return $item->hasUpdates() || !in_array($item->getId(), $storedItemIds);
                });
                $count = count($items);
            }
            if ($libraryFilter !== null) {
                $items = array_filter([...$items], fn (OwnedItemInfo $item) => $libraryFilter($item->getId()));
                $count = count($items);
            }
            if (($ids = $this->getIdFileIds($input)) !== null) {
                $items = array_filter([...$items], fn (OwnedItemInfo $item) => isset($ids[$item->getId()]));
                $count = count($items);
            }
            $items = array_values([...$items]);
            $count = count($items);
            $machine->emit('total', ['games' => $count]);
            $progressBar = $machine->isEnabled()
                ? new ProgressBar(new NullOutput(), $count)
                : $io->createProgressBar($count);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
            $index = 0;
            // several games are fetched at once, all their requests run in parallel
            foreach (array_chunk($items, self::PARALLEL_GAMES) as $batch) {
                $batchResults = [];
                if ($type === MediaType::Game) {
                    $progressBar->setMessage($batch[0]->getTitle());
                    try {
                        $batchResults = $this->ownedItemsManager->getGameDetails($batch, (int) $timeout);
                    } catch (Throwable) {
                        // e.g. a login problem, every game is retried on its own below
                    }
                }

                foreach ($batch as $item) {
                    $machine->emit('game', ['id' => $item->getId(), 'title' => $item->getTitle(), 'index' => $index++]);
                    $progressBar->setMessage($item->getTitle());
                    $result = array_key_exists($item->getId(), $batchResults) ? $batchResults[$item->getId()] : false;

                    try {
                        if ($result === false || $result instanceof Throwable) {
                            // not fetched in the batch or it failed there: fetch on its own, with retries
                            $result = $this->retryService->retry(
                                fn () => $this->ownedItemsManager->getItemDetail($item, httpTimeout: (int) $timeout, cached: false),
                                $input->getOption('retry'),
                                $input->getOption('retry-delay'),
                            );
                        }
                        if ($result !== null) {
                            $this->ownedItemsManager->storeSingleGameData($result);
                        }
                        $progressBar->advance();
                    } catch (TooManyRetriesException $e) {
                        if (!$input->getOption('skip-errors')) {
                            if (!count($e->exceptions)) {
                                throw $e;
                            }
                            throw $e->exceptions[array_key_last($e->exceptions)];
                        }
                        $progressBar->advance();
                        $io->note("{$item->getTitle()} couldn't be downloaded");
                        $machine->emit('done', ['name' => $item->getTitle(), 'status' => 'failed']);
                        continue;
                    }
                    $machine->emit('done', ['name' => $item->getTitle(), 'status' => 'updated']);
                }
            }
            $progressBar->finish();
        }

        $machine->emit('finished');
        $io->success('Local data successfully updated');

        return self::SUCCESS;
    }

    /**
     * @return array<MediaType>
     */
    private function getTypes(InputInterface $input): array
    {
        //$games = $input->getOption('games');
        //$movies = $input->getOption('movies');
        //
        $result = [];
        //
        //if ($games) {
        //    $result[] = MediaType::Game;
        //}
        //if ($movies) {
        //    $result[] = MediaType::Movie;
        //}
        //
        if (!count($result)) {
            $result = [MediaType::Game/*MediaType::Movie*/];
        }

        return $result;
    }
}
