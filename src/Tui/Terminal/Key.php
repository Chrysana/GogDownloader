<?php

namespace App\Tui\Terminal;

/**
 * A single input event. Special keys have a name (e.g. "up", "enter", "ctrl+c", "f1"),
 * printable characters have the name "char" and the character in $char.
 *
 * Mouse events use the names "click", "doubleclick", "rightclick", "scrollup" and "scrolldown"
 * with the zero based cell position in $x and $y.
 */
final readonly class Key
{
    public const MOUSE_EVENTS = ['click', 'doubleclick', 'rightclick', 'scrollup', 'scrolldown'];

    public function __construct(
        public string $name,
        public string $char = '',
        public int $x = -1,
        public int $y = -1,
    ) {
    }

    public static function char(string $char): self
    {
        return new self('char', $char);
    }

    public static function mouse(string $name, int $x, int $y): self
    {
        return new self($name, x: $x, y: $y);
    }

    public function is(string ...$names): bool
    {
        return in_array($this->name, $names, true);
    }

    public function isChar(?string $char = null): bool
    {
        if ($this->name !== 'char') {
            return false;
        }

        return $char === null || mb_strtolower($this->char) === mb_strtolower($char);
    }

    public function isMouse(): bool
    {
        return in_array($this->name, self::MOUSE_EVENTS, true);
    }

    public function isScroll(): bool
    {
        return $this->name === 'scrollup' || $this->name === 'scrolldown';
    }
}
