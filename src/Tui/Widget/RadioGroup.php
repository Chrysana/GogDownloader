<?php

namespace App\Tui\Widget;

use App\Tui\Canvas;
use App\Tui\Terminal\Key;
use App\Tui\Theme;
use Closure;

/**
 * A set of mutually exclusive options. Rendered horizontally, or vertically (one option per line).
 * Left/right (or space) change the selected option.
 */
final class RadioGroup extends Widget
{
    /** @var list<array{string, int, int, int}> value, x, y, width of each option as last rendered */
    private array $areas = [];

    /**
     * @param array<string, string> $options value => label
     */
    public function __construct(
        public array $options,
        public string $value,
        private readonly ?Closure $onChange = null,
        public bool $vertical = false,
    ) {
    }

    public function getHeight(): int
    {
        return $this->vertical ? count($this->options) : 1;
    }

    public function render(Canvas $canvas, bool $focused): void
    {
        if (!$this->visible) {
            return;
        }

        $x = $this->x;
        $y = $this->y;
        $this->areas = [];
        foreach ($this->options as $value => $label) {
            $selected = (string) $value === $this->value;
            $style = $focused && $selected ? Theme::FOCUSED : Theme::VALUE;
            $written = $canvas->text($x, $y, $selected ? '(X)' : '( )', $style);
            $written += 1 + $canvas->text($x + $written + 1, $y, $label, Theme::LABEL);
            $this->areas[] = [(string) $value, $x, $y, $written];
            if ($this->vertical) {
                ++$y;
            } else {
                $x += $written + 3;
            }
        }
    }

    public function contains(int $x, int $y): bool
    {
        return $this->visible && $this->findOption($x, $y) !== null;
    }

    public function handleMouse(Key $event): bool
    {
        if (!$event->is('click', 'doubleclick') || !$this->enabled) {
            return false;
        }
        $value = $this->findOption($event->x, $event->y);
        if ($value !== null && $value !== $this->value) {
            $this->value = $value;
            if ($this->onChange) {
                ($this->onChange)($this->value);
            }
        }

        return $value !== null;
    }

    public function handleKey(Key $key): bool
    {
        $values = array_map('strval', array_keys($this->options));
        $index = array_search($this->value, $values, true);
        $index = $index === false ? 0 : $index;

        $previous = $this->vertical ? ['left', 'up'] : ['left'];
        $next = $this->vertical ? ['right', 'down'] : ['right'];

        if ($key->is(...$previous)) {
            if ($this->vertical && $index === 0) {
                return false; // let focus move up
            }
            $index = max(0, $index - 1);
        } elseif ($key->is(...$next)) {
            if ($this->vertical && $index === count($values) - 1) {
                return false;
            }
            $index = min(count($values) - 1, $index + 1);
        } elseif ($key->isChar(' ') || $key->is('enter')) {
            $index = ($index + 1) % count($values);
        } else {
            return false;
        }

        if ($values[$index] !== $this->value) {
            $this->value = $values[$index];
            if ($this->onChange) {
                ($this->onChange)($this->value);
            }
        }

        return true;
    }

    public function capturesVerticalKeys(): bool
    {
        return $this->vertical;
    }

    private function findOption(int $x, int $y): ?string
    {
        foreach ($this->areas as [$value, $areaX, $areaY, $width]) {
            if ($y === $areaY && $x >= $areaX && $x < $areaX + $width) {
                return $value;
            }
        }

        return null;
    }
}
