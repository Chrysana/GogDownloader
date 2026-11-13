<?php

namespace App\Command;

use App\Service\AuthenticationManager;
use App\Service\FolderMigrator;
use App\Service\NewVersionChecker;
use App\Service\OwnedItemsManager;
use App\Service\Persistence\PersistenceManager;
use App\Tui\App;
use App\Tui\Terminal\UnixConsole;
use App\Tui\Terminal\WindowsConsole;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand('tui', description: 'Starts the interactive text interface (the default when no command is given).', aliases: ['ui'])]
final class TuiCommand extends Command
{
    public function __construct(
        private readonly OwnedItemsManager $ownedItemsManager,
        private readonly PersistenceManager $persistence,
        private readonly AuthenticationManager $authentication,
        private readonly FolderMigrator $folderMigrator,
        private readonly NewVersionChecker $versionChecker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $console = PHP_OS_FAMILY === 'Windows' ? new WindowsConsole() : new UnixConsole();
        } catch (Throwable $e) {
            $io->error([$e->getMessage(), 'You can still use all the regular commands, run with "list" to see them.']);

            return self::FAILURE;
        }

        $newVersion = null;

        try {
            if ($this->versionChecker->newVersionAvailable()) {
                $newVersion = $this->versionChecker->getLatestVersion();
            }
        } catch (Throwable) {
            // offline or rate limited, not important
        }

        $app = new App(
            console: $console,
            ownedItemsManager: $this->ownedItemsManager,
            persistence: $this->persistence,
            authentication: $this->authentication,
            folderMigrator: $this->folderMigrator,
            version: $this->getApplication()?->getVersion() ?? 'dev',
            newVersion: $newVersion,
        );

        try {
            $app->run();
        } catch (Throwable $e) {
            $io->error([
                'The interactive interface crashed: ' . $e->getMessage(),
                "{$e->getFile()}:{$e->getLine()}",
            ]);
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
