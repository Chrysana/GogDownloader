<?php

namespace App\Tui\Widget;

use App\Tui\Canvas;
use App\Tui\Terminal\Key;
use App\Tui\Theme;
use Closure;

final class Checkbox extends Widget
{
    public function __construct(
        public string $label,
        public bool $checked = false,
        private readonly ?Closure $onChange = null,
    ) {
    }

    public function render(Canvas $canvas, bool $focused): void
    {
        if (!$this->visible) {
            return;
        }
        $mark = $this->checked ? 'X' : ' ';
        $written = $canvas->text($this->x, $this->y, "[{$mark}]", $focused ? Theme::FOCUSED : Theme::VALUE);
        $canvas->text($this->x + $written + 1, $this->y, $this->label, $this->enabled ? Theme::LABEL : Theme::DIM);
    }

    public function contains(int $x, int $y): bool
    {
        return $this->visible && $y === $this->y && $x >= $this->x && $x < $this->x + 4 + Canvas::width($this->label);
    }

    public function handleMouse(Key $event): bool
    {
        // a double click is two clicks, toggle on each of them
        if ($event->is('click', 'doubleclick') && $this->enabled) {
            return $this->handleKey(new Key('enter'));
        }

        return false;
    }

    public function handleKey(Key $key): bool
    {
        if ($key->is('enter') || $key->isChar(' ')) {
            $this->checked = !$this->checked;
            if ($this->onChange) {
                ($this->onChange)($this->checked);
            }

            return true;
        }

        return false;
    }
}
