<?php

namespace App\Tui\Widget;

use App\Tui\Canvas;
use App\Tui\Terminal\Key;
use App\Tui\Theme;
use Closure;

final class Button extends Widget
{
    public function __construct(
        public string $label,
        private readonly Closure $onPress,
        public ?int $width = null,
    ) {
    }

    public function getWidth(): int
    {
        return $this->width ?? Canvas::width($this->label) + 4;
    }

    public function render(Canvas $canvas, bool $focused): void
    {
        if (!$this->visible) {
            return;
        }
        $style = !$this->enabled ? '37;100' : ($focused ? Theme::BUTTON_FOCUSED : Theme::BUTTON);
        $canvas->textFixed($this->x, $this->y, $this->label, $style, $this->getWidth(), 'center');
    }

    public function contains(int $x, int $y): bool
    {
        return $this->visible && $y === $this->y && $x >= $this->x && $x < $this->x + $this->getWidth();
    }

    public function handleMouse(Key $event): bool
    {
        if ($event->is('click') && $this->enabled) {
            ($this->onPress)();

            return true;
        }

        return $event->is('doubleclick'); // swallow the second click of a double click
    }

    public function handleKey(Key $key): bool
    {
        if ($key->is('enter') || $key->isChar(' ')) {
            ($this->onPress)();

            return true;
        }

        return false;
    }
}
