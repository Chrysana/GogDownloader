<?php

namespace App\Tui\Terminal;

use RuntimeException;

/**
 * Raw terminal access on Linux / macOS using stty and ANSI escape sequences.
 */
final class UnixConsole implements Console
{
    private const SEQUENCES = [
        "\e[A" => 'up', "\e[B" => 'down', "\e[C" => 'right', "\e[D" => 'left',
        "\eOA" => 'up', "\eOB" => 'down', "\eOC" => 'right', "\eOD" => 'left',
        "\e[H" => 'home', "\e[F" => 'end', "\eOH" => 'home', "\eOF" => 'end',
        "\e[1~" => 'home', "\e[4~" => 'end', "\e[7~" => 'home', "\e[8~" => 'end',
        "\e[2~" => 'insert', "\e[3~" => 'delete', "\e[5~" => 'pageup', "\e[6~" => 'pagedown',
        "\e[Z" => 'backtab',
        "\eOP" => 'f1', "\eOQ" => 'f2', "\eOR" => 'f3', "\eOS" => 'f4',
        "\e[11~" => 'f1', "\e[12~" => 'f2', "\e[13~" => 'f3', "\e[14~" => 'f4',
        "\e[15~" => 'f5', "\e[17~" => 'f6', "\e[18~" => 'f7', "\e[19~" => 'f8',
        "\e[20~" => 'f9', "\e[21~" => 'f10', "\e[23~" => 'f11', "\e[24~" => 'f12',
    ];

    private ?string $originalSettings = null;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    private string $buffer = '';

    private array $size = [80, 24];

    private float $sizeCheckedAt = 0;

    public function __construct()
    {
        $this->stdin = fopen('php://stdin', 'rb');
        $this->stdout = fopen('php://stdout', 'wb');
    }

    public function enable(): void
    {
        if (!stream_isatty($this->stdin)) {
            throw new RuntimeException('The interactive UI must be run in a terminal.');
        }

        $settings = shell_exec('stty -g 2>/dev/null');
        if (!is_string($settings) || trim($settings) === '') {
            throw new RuntimeException('Failed to configure the terminal (stty is not available).');
        }
        $this->originalSettings = trim($settings);
        shell_exec('stty -icanon -echo -isig -ixon min 1 time 0 2>/dev/null');
        stream_set_blocking($this->stdin, false);
    }

    public function restore(): void
    {
        if ($this->originalSettings !== null) {
            shell_exec('stty ' . escapeshellarg($this->originalSettings) . ' 2>/dev/null');
            $this->originalSettings = null;
        }
        stream_set_blocking($this->stdin, true);
    }

    public function getSize(): array
    {
        if (microtime(true) - $this->sizeCheckedAt > 0.5) {
            $this->sizeCheckedAt = microtime(true);
            $output = shell_exec('stty size 2>/dev/null');
            if (is_string($output) && preg_match('/^(\d+)\s+(\d+)/', trim($output), $matches)) {
                $this->size = [max(1, (int) $matches[2]), max(1, (int) $matches[1])];
            }
        }

        return $this->size;
    }

    public function readKeys(int $timeoutMs): array
    {
        if ($this->buffer === '') {
            $read = [$this->stdin];
            $write = $except = null;
            if (!@stream_select($read, $write, $except, 0, max(0, $timeoutMs) * 1000)) {
                return [];
            }
        }

        while (($chunk = fread($this->stdin, 4096)) !== false && $chunk !== '') {
            $this->buffer .= $chunk;
        }

        $keys = [];
        while ($this->buffer !== '') {
            $key = $this->parseNext();
            if ($key === null) {
                break;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    public function write(string $data): void
    {
        fwrite($this->stdout, $data);
        fflush($this->stdout);
    }

    public function setMouseEnabled(bool $enabled): void
    {
        // 1000: report button presses, 1006: SGR extended coordinates
        $this->write($enabled ? "\e[?1000h\e[?1006h" : "\e[?1000l\e[?1006l");
    }

    private function skip(int $bytes): Key
    {
        $this->buffer = substr($this->buffer, $bytes);

        return new Key('unknown');
    }

    private function parseNext(): ?Key
    {
        $buffer = $this->buffer;

        if ($buffer[0] === "\e") {
            // SGR mouse report: ESC [ < button ; x ; y (M = press, m = release)
            if (str_starts_with($buffer, "\e[<")) {
                if (!preg_match('/^\e\[<(\d+);(\d+);(\d+)([Mm])/', $buffer, $matches)) {
                    return strlen($buffer) < 32 ? null : $this->skip(3); // incomplete, or garbage
                }
                $this->buffer = substr($buffer, strlen($matches[0]));
                $button = (int) $matches[1];
                $x = (int) $matches[2] - 1;
                $y = (int) $matches[3] - 1;
                $isPress = $matches[4] === 'M';

                return match (true) {
                    $button === 64 => Key::mouse('scrollup', $x, $y),
                    $button === 65 => Key::mouse('scrolldown', $x, $y),
                    $isPress && $button === 0 => Key::mouse('click', $x, $y),
                    $isPress && $button === 2 => Key::mouse('rightclick', $x, $y),
                    default => new Key('unknown'),
                };
            }
            if (strlen($buffer) === 1) {
                $this->buffer = '';

                return new Key('esc');
            }
            foreach (self::SEQUENCES as $sequence => $name) {
                if (str_starts_with($buffer, $sequence)) {
                    $this->buffer = substr($buffer, strlen($sequence));

                    return new Key($name);
                }
            }
            // unknown CSI sequence: skip it entirely
            if (preg_match('/^\e\[[0-9;?]*[ -\/]*[@-~]/', $buffer, $matches)) {
                $this->buffer = substr($buffer, strlen($matches[0]));

                return new Key('unknown');
            }
            // alt + key, treat as plain escape followed by the key
            $this->buffer = substr($buffer, 1);

            return new Key('esc');
        }

        $byte = ord($buffer[0]);
        if ($byte < 32 || $byte === 127) {
            $this->buffer = substr($buffer, 1);

            return match ($byte) {
                10, 13 => new Key('enter'),
                9 => new Key('tab'),
                8, 127 => new Key('backspace'),
                0 => new Key('unknown'),
                default => new Key('ctrl+' . chr($byte + 96)),
            };
        }

        // UTF-8 character
        $length = match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
        if (strlen($buffer) < $length) {
            return null; // incomplete, wait for more bytes
        }
        $this->buffer = substr($buffer, $length);

        return Key::char(substr($buffer, 0, $length));
    }
}
