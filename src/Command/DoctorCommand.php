<?php

namespace App\Command;

use App\Service\Factory\PersistenceManagerFactory;
use App\Service\Persistence\PersistenceManager;
use App\Service\Persistence\PersistenceManagerSqlite;
use App\Service\SecretProtector;
use App\Tui\Job;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('doctor', description: 'Shows information about the runtime environment, useful when reporting problems.')]
final class DoctorCommand extends Command
{
    private const REQUIRED_EXTENSIONS = ['curl', 'openssl', 'pdo_sqlite', 'mbstring', 'simplexml', 'dom', 'zlib', 'ctype', 'iconv'];

    public function __construct(
        private readonly PersistenceManager $persistence,
        private readonly SecretProtector $protector,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn (string $extension) => !extension_loaded($extension)));
        $tuiSupported = PHP_OS_FAMILY === 'Windows'
            ? extension_loaded('ffi') && in_array(strtolower((string) ini_get('ffi.enable')), ['1', 'on', 'true', 'preload'], true)
            : function_exists('shell_exec');

        $io->definitionList(
            ['Application' => $this->getApplication()?->getVersion() ?? 'unknown'],
            ['PHP' => PHP_VERSION . ' (' . PHP_SAPI . ', ' . PHP_OS_FAMILY . ' ' . php_uname('m') . ')'],
            ['Binary' => PHP_BINARY],
            ['Self command' => implode(' ', Job::getSelfCommand())],
            ['Data directory' => $_ENV['CONFIG_DIRECTORY'] ?? getcwd()],
            ['Storage' => $this->persistence instanceof PersistenceManagerSqlite ? 'sqlite' : 'files (sqlite not available)'],
            ['SQLite available' => PersistenceManagerFactory::isSqliteAvailable() ? 'yes' : 'no'],
            ['FFI' => extension_loaded('ffi') ? 'yes (ffi.enable=' . ini_get('ffi.enable') . ')' : 'no'],
            ['Interactive UI' => $tuiSupported ? 'supported' : 'not supported'],
            ['Missing extensions' => count($missing) ? implode(', ', $missing) : 'none'],
            ['Login storage' => $this->protector->describe()],
            ['Logged in' => $this->persistence->getAuthorization() !== null ? 'yes' : 'no'],
        );

        if (count($missing)) {
            $io->error('Some required extensions are missing.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
