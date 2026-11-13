<?php

namespace App\Command;

use App\Enum\NamingConvention;
use App\Enum\Setting;
use App\Service\FolderMigrator;
use App\Service\Persistence\PersistenceManager;
use App\Trait\MigrationCheckerTrait;
use App\Trait\TargetDirectoryTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('migrate-naming-scheme', description: 'Changes how game folders are named (e.g. to the game titles) and renames the folders you already downloaded.', aliases: ['rename-folders'])]
final class MigrateNamingSchemeCommand extends Command
{
    use TargetDirectoryTrait;
    use MigrationCheckerTrait;

    public function __construct(
        private readonly PersistenceManager $persistence,
        private readonly FolderMigrator $migrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $conventions = implode(', ', array_map(
            fn (NamingConvention $convention) => "{$convention->value} ({$convention->getDescription()})",
            array_filter(NamingConvention::cases(), fn (NamingConvention $convention) => $convention !== NamingConvention::Custom),
        ));

        $this
            ->addArgument(
                'directory',
                InputArgument::OPTIONAL,
                'The download directory, defaults to the configured one.',
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_REQUIRED,
                "The naming scheme to switch to: {$conventions}.",
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'The naming scheme the existing folders use, defaults to the currently configured one.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Only show what would be renamed, without changing anything.',
            )
            ->addOption(
                'yes',
                'y',
                InputOption::VALUE_NONE,
                "Don't ask for confirmation.",
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->showInfoIfMigrationsAreNeeded($io, $this->persistence);

        $current = NamingConvention::tryFrom((string) $this->persistence->getSetting(Setting::NamingConvention)) ?? NamingConvention::GogSlug;
        $from = $input->getOption('from') !== null ? NamingConvention::tryFrom($input->getOption('from')) : $current;
        $to = $input->getOption('to') !== null
            ? NamingConvention::tryFrom($input->getOption('to'))
            // without --to this keeps the original purpose of the command: leaving the deprecated custom scheme
            : ($current === NamingConvention::Custom ? NamingConvention::GogSlug : null);

        if ($from === null || ($input->getOption('to') !== null && $to === null)) {
            $io->error('Unknown naming scheme, see --help for the list of supported ones.');

            return Command::FAILURE;
        }
        if ($to === null) {
            $io->error("Please specify the naming scheme to switch to using --to, e.g. --to=title. The current one is '{$current->value}'.");

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $configuredDirectory = $input->getArgument('directory')
            ?? $_ENV['DOWNLOAD_DIRECTORY']
            ?? $this->persistence->getSetting(Setting::DownloadPath);
        if (!$configuredDirectory && !$dryRun && !$input->getOption('yes')) {
            if (!$input->isInteractive()) {
                $io->error('Refusing to rename folders without an explicitly set download directory, pass it as an argument (or use --yes to use the current directory).');

                return Command::FAILURE;
            }
            if (!$io->askQuestion(new ConfirmationQuestion('You have not specified any download directory, which means the current directory is used. Is that really what you want?', false))) {
                return Command::FAILURE;
            }
        }
        $baseDirectory = self::makeAbsolutePath((string) ($configuredDirectory ?? getcwd()));

        $io->writeln(sprintf('Download directory: <info>%s</info>', $baseDirectory));
        $io->writeln(sprintf('Renaming from <info>%s</info> to <info>%s</info> (%s)', $from->value, $to->value, $to->getDescription()));
        $io->newLine();

        $plan = $this->migrator->plan($baseDirectory, $from, $to);
        $this->printPlan($io, $plan, $output->isVerbose());

        $moves = count(array_filter($plan, fn (array $entry) => $entry['status'] === FolderMigrator::MOVE));
        $unknown = $this->migrator->findUnknownFolders($baseDirectory);
        if (count($unknown)) {
            $io->note(array_merge(
                ['These folders do not belong to any game in the local database and will be left untouched (run update-database if you bought them recently):'],
                array_map(fn (string $folder) => "  {$folder}", $unknown),
            ));
        }

        if ($dryRun) {
            $io->success(sprintf('Dry run: %d folder(s) would be renamed, nothing was changed.', $moves));

            return Command::SUCCESS;
        }

        if ($moves > 0 && !$input->getOption('yes') && $input->isInteractive()
            && !$io->askQuestion(new ConfirmationQuestion("Rename {$moves} folder(s) and switch to the '{$to->value}' naming scheme?", true))
        ) {
            return Command::FAILURE;
        }

        $result = $this->migrator->execute($plan);
        $failed = array_filter($result, fn (array $entry) => $entry['status'] === FolderMigrator::FAILED);
        foreach ($result as $entry) {
            if ($entry['status'] === FolderMigrator::MOVED && $output->isVerbose()) {
                $io->writeln("Renamed: {$entry['from']} -> {$entry['to']}");
            }
            if ($entry['status'] === FolderMigrator::FAILED) {
                $io->error("{$entry['game']}: {$entry['message']}");
            }
        }

        // the setting is switched even when some folders failed: new downloads should use the new names,
        // and the failed folders can be renamed by running the command again with --from
        $this->persistence->storeSetting(Setting::NamingConvention, $to->value);

        $moved = count(array_filter($result, fn (array $entry) => $entry['status'] === FolderMigrator::MOVED));
        if (count($failed)) {
            $io->warning(sprintf('Renamed %d folder(s), %d failed. Run the command again with --from=%s to retry.', $moved, count($failed), $from->value));

            return Command::FAILURE;
        }

        $io->success(sprintf("Renamed %d folder(s). New downloads use the '%s' naming scheme.", $moved, $to->value));

        return Command::SUCCESS;
    }

    /**
     * @param list<array{game: string, from: string, to: string, status: string, message?: string}> $plan
     */
    private function printPlan(SymfonyStyle $io, array $plan, bool $verbose): void
    {
        $rows = [];
        $counts = [];
        foreach ($plan as $entry) {
            $counts[$entry['status']] = ($counts[$entry['status']] ?? 0) + 1;
            if (!$verbose && in_array($entry['status'], [FolderMigrator::MISSING, FolderMigrator::DONE], true)) {
                continue;
            }
            $rows[] = [
                $entry['status'],
                basename($entry['from']),
                basename($entry['to']),
                $entry['message'] ?? '',
            ];
        }

        if (count($rows)) {
            $io->table(['Action', 'Current folder', 'New folder', 'Note'], $rows);
        }
        $io->writeln(sprintf(
            'To rename: %d · already named correctly: %d · not downloaded: %d · conflicts: %d',
            $counts[FolderMigrator::MOVE] ?? 0,
            $counts[FolderMigrator::DONE] ?? 0,
            $counts[FolderMigrator::MISSING] ?? 0,
            $counts[FolderMigrator::CONFLICT] ?? 0,
        ));
        if (!$verbose && (($counts[FolderMigrator::DONE] ?? 0) || ($counts[FolderMigrator::MISSING] ?? 0))) {
            $io->writeln('<comment>Use -v to list every game.</comment>');
        }
    }
}
