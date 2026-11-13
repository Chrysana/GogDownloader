<?php

namespace App\Service;

use JsonException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Emits machine readable progress events (one JSON object per line, prefixed with a marker) on the standard output.
 * Used by the interactive UI, which runs long operations as child processes and parses these events.
 */
final class MachineProgress
{
    public const PREFIX = '##gogdl ';

    private float $lastProgress = 0.0;

    public function __construct(
        private readonly ?OutputInterface $output = null,
    ) {
    }

    public static function disabled(): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return $this->output !== null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function emit(string $event, array $data = []): void
    {
        if ($this->output === null) {
            return;
        }

        try {
            $json = json_encode(['e' => $event, ...$data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return;
        }
        $this->output->writeln(self::PREFIX . $json, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Rate limited progress event.
     */
    public function progress(int $current, int $total): void
    {
        if ($this->output === null) {
            return;
        }

        $now = microtime(true);
        if ($current < $total && $now - $this->lastProgress < 0.2) {
            return;
        }
        $this->lastProgress = $now;
        $this->emit('progress', ['cur' => $current, 'total' => $total]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function parse(string $line): ?array
    {
        if (!str_starts_with($line, self::PREFIX)) {
            return null;
        }

        try {
            $data = json_decode(substr($line, strlen(self::PREFIX)), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) && isset($data['e']) ? $data : null;
    }
}
