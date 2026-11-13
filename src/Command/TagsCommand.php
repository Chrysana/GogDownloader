<?php

namespace App\Command;

use App\Service\OwnedItemsManager;
use App\Service\Persistence\PersistenceManager;
use App\Trait\MigrationCheckerTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('tags', description: 'Lists the tags (and hidden games count) from your GOG library.')]
final class TagsCommand extends Command
{
    use MigrationCheckerTrait;

    public function __construct(
        private readonly OwnedItemsManager $ownedItemsManager,
        private readonly PersistenceManager $persistence,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                name: 'refresh',
                shortcut: 'r',
                mode: InputOption::VALUE_NONE,
                description: 'Fetch the library information from GOG instead of using the local copy.',
            )
            ->addOption(
                name: 'idle-timeout',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Set the idle timeout in seconds for http requests',
                default: 3,
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->showInfoIfMigrationsAreNeeded($io, $this->persistence);

        $library = $input->getOption('refresh') ? [] : $this->ownedItemsManager->getLibrary();
        if (!count($library)) {
            $library = $this->ownedItemsManager->refreshLibrary(httpTimeout: (int) $input->getOption('idle-timeout'));
        }

        $counts = [];
        $hidden = 0;
        foreach ($library as $item) {
            if ($item->hidden) {
                ++$hidden;
            }
            foreach ($item->tags as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        uksort($counts, strnatcasecmp(...));

        if (!count($counts)) {
            $io->note("You don't have any tags in your GOG library.");
        } else {
            $io->table(['Tag', 'Games'], array_map(
                fn (string $tag, int $count) => [$tag, $count],
                array_keys($counts),
                array_values($counts),
            ));
        }

        $io->writeln(sprintf('Games in library: %d, hidden: %d', count($library), $hidden));

        return Command::SUCCESS;
    }
}
