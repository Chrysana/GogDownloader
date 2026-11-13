<?php

namespace App\Tui;

use App\Service\MachineProgress;
use Phar;
use Symfony\Component\Process\Process;

/**
 * A long running operation (download / database update) executed as a child process of this application.
 * Progress is parsed from the machine readable events the child emits.
 */
final class Job
{
    public const MAX_RESULTS = 500;

    public ?int $totalGames = null;

    public int $gameIndex = -1;

    public ?string $currentGame = null;

    public ?string $currentFile = null;

    public int $fileCurrent = 0;

    public int $fileTotal = 0;

    public int $filesDone = 0;

    public int $filesFailed = 0;

    public int $filesSkipped = 0;

    public int $bytesDownloaded = 0;

    public float $startedAt;

    public ?float $finishedAt = null;

    public bool $cancelled = false;

    public readonly bool $testRun;

    /** files a test run would download, and the bytes it would transfer */
    public int $testFiles = 0;

    public int $testBytes = 0;

    /** @var list<array{string, string, string}> status, game, name */
    public array $results = [];

    private Process $process;

    private string $stdoutBuffer = '';

    private string $stderrBuffer = '';

    private float $speed = 0;

    private int $lastSpeedBytes = 0;

    private float $lastSpeedTime = 0;

    private int $previousFileCurrent = 0;

    /**
     * @param list<string>           $arguments      arguments for this application (command name first)
     * @param \Closure(string): void $log
     * @param list<string>           $temporaryFiles files to delete once the job is finished
     */
    public function __construct(
        public readonly string $title,
        public readonly string $type,
        array $arguments,
        private readonly \Closure $log,
        private readonly array $temporaryFiles = [],
    ) {
        $this->testRun = in_array('--test-run', $arguments, true);
        $environment = ['GOG_DOWNLOADER_CHILD' => '1'];
        if (isset($_ENV['CONFIG_DIRECTORY'])) {
            $environment['CONFIG_DIRECTORY'] = $_ENV['CONFIG_DIRECTORY'];
        }

        $this->process = new Process([...self::getSelfCommand(), ...$arguments, '--no-ansi', '--no-interaction'], getcwd() ?: null, $environment, null, null);
        $this->startedAt = microtime(true);
        $this->lastSpeedTime = $this->startedAt;
        ($this->log)("Started: {$this->title}");
        $this->process->start();
    }

    /**
     * @return list<string> the command used to start this application again
     */
    public static function getSelfCommand(): array
    {
        $phar = class_exists(Phar::class) ? Phar::running(false) : '';
        if ($phar !== '') {
            // a self-contained binary (php runtime with the phar appended) runs the phar by itself
            if (realpath($phar) === realpath(PHP_BINARY)) {
                return [PHP_BINARY];
            }

            return [PHP_BINARY, $phar];
        }

        $script = defined('GOG_DOWNLOADER_ENTRYPOINT') ? GOG_DOWNLOADER_ENTRYPOINT : dirname(__DIR__, 2) . '/bin/app.php';

        return [PHP_BINARY, realpath($script) ?: $script];
    }

    public function isRunning(): bool
    {
        return $this->finishedAt === null;
    }

    public function poll(): bool
    {
        if ($this->finishedAt !== null) {
            return false;
        }

        $changed = false;
        $this->stdoutBuffer .= $this->process->getIncrementalOutput();
        $this->stderrBuffer .= $this->process->getIncrementalErrorOutput();
        $changed = $this->drainLines($this->stdoutBuffer) || $changed;
        $changed = $this->drainLines($this->stderrBuffer) || $changed;

        $now = microtime(true);
        if ($now - $this->lastSpeedTime >= 1.0) {
            $instant = ($this->bytesDownloaded - $this->lastSpeedBytes) / ($now - $this->lastSpeedTime);
            $this->speed = $this->speed > 0 ? $this->speed * 0.6 + $instant * 0.4 : $instant;
            $this->lastSpeedBytes = $this->bytesDownloaded;
            $this->lastSpeedTime = $now;
            $changed = true;
        }

        if (!$this->process->isRunning()) {
            $this->stdoutBuffer .= $this->process->getIncrementalOutput() . "\n";
            $this->stderrBuffer .= $this->process->getIncrementalErrorOutput() . "\n";
            $this->drainLines($this->stdoutBuffer);
            $this->drainLines($this->stderrBuffer);
            $this->finishedAt = $now;
            $exitCode = $this->process->getExitCode();
            if ($this->cancelled) {
                ($this->log)("Cancelled: {$this->title}");
            } elseif ($exitCode === 0) {
                ($this->log)("Finished: {$this->title}");
            } else {
                ($this->log)("Failed: {$this->title} (exit code {$exitCode})");
            }
            $this->cleanup();

            return true;
        }

        return $changed;
    }

    public function cancel(): void
    {
        if ($this->finishedAt !== null) {
            return;
        }
        $this->cancelled = true;
        $this->process->stop(2);
    }

    public function succeeded(): bool
    {
        return $this->finishedAt !== null && !$this->cancelled && $this->process->getExitCode() === 0;
    }

    public function getSpeed(): float
    {
        return max(0, $this->speed);
    }

    public function getElapsed(): float
    {
        return ($this->finishedAt ?? microtime(true)) - $this->startedAt;
    }

    private function drainLines(string &$buffer): bool
    {
        $changed = false;
        while (($position = strpos($buffer, "\n")) !== false) {
            $line = rtrim(substr($buffer, 0, $position), "\r");
            $buffer = substr($buffer, $position + 1);
            $this->handleLine($line);
            $changed = true;
        }

        return $changed;
    }

    private function handleLine(string $line): void
    {
        $event = MachineProgress::parse($line);
        if ($event === null) {
            $line = trim($line);
            // skip decoration lines from the console style output
            if ($line !== '' && !preg_match('/^[\s\-=!\[\]]*$/', $line)) {
                ($this->log)($line);
            }

            return;
        }

        switch ($event['e']) {
            case 'total':
                $this->totalGames = (int) $event['games'];
                break;
            case 'game':
                $this->gameIndex = (int) $event['index'];
                $this->currentGame = (string) $event['title'];
                $this->currentFile = null;
                break;
            case 'file':
                $this->currentFile = (string) $event['name'];
                $this->fileTotal = (int) ($event['size'] ?? 0);
                $this->fileCurrent = (int) ($event['startAt'] ?? 0);
                $this->previousFileCurrent = $this->fileCurrent;
                break;
            case 'progress':
                $current = (int) $event['cur'];
                $this->bytesDownloaded += max(0, $current - $this->previousFileCurrent);
                $this->previousFileCurrent = $current;
                $this->fileCurrent = $current;
                $this->fileTotal = (int) $event['total'];
                break;
            case 'done':
                $status = (string) $event['status'];
                match ($status) {
                    'failed' => ++$this->filesFailed,
                    'skipped' => ++$this->filesSkipped,
                    default => ++$this->filesDone,
                };
                $this->results[] = [$status, (string) ($event['game'] ?? ''), (string) ($event['name'] ?? '')];
                if (count($this->results) > self::MAX_RESULTS) {
                    array_shift($this->results);
                }
                if ($status === 'failed') {
                    ($this->log)('Failed: ' . trim(($event['game'] ?? '') . ' / ' . ($event['name'] ?? ''), ' /'));
                }
                $this->currentFile = null;
                break;
            case 'test':
                if (($event['action'] ?? '') !== 'invalid') {
                    ++$this->testFiles;
                    $this->testBytes += max(0, (int) $event['size'] - (int) ($event['startAt'] ?? 0));
                }
                $this->currentFile = (string) ($event['name'] ?? '');
                ($this->log)(sprintf(
                    'Would %s: %s -> %s',
                    ($event['action'] ?? '') === 'resume' ? 'resume' : 'download',
                    $event['name'] ?? '',
                    $event['path'] ?? '',
                ));
                break;
            case 'finished':
                $this->currentFile = null;
                break;
        }
    }

    private function cleanup(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
