<?php

namespace App\Tui\Widget;

use App\Tui\Canvas;
use App\Tui\Terminal\Key;
use App\Tui\Theme;
use Closure;

/**
 * A single value picked from a list with left / right (or space / enter), rendered as "< value >".
 */
final class Selector extends Widget
{
    /**
     * @param Closure(): list<string> $options
     */
    public function __construct(
        private readonly Closure $options,
        public string $value,
        public int $width,
        private readonly ?Closure $onChange = null,
    ) {
    }

    public function render(Canvas $canvas, bool $focused): void
    {
        if (!$this->visible) {
            return;
        }
        $style = $focused ? Theme::INPUT_FOCUSED : Theme::INPUT;
        $canvas->textFixed($this->x, $this->y, '◄ ' . Canvas::fit($this->value, $this->width - 4, 'center') . ' ►', $style, $this->width);
    }

    public function contains(int $x, int $y): bool
    {
        return $this->visible && $y === $this->y && $x >= $this->x && $x < $this->x + $this->width;
    }

    public function handleMouse(Key $event): bool
    {
        return match (true) {
            // left arrow area goes back, everything else forward
            $event->is('click', 'doubleclick') => $this->handleKey(new Key($event->x < $this->x + 3 ? 'left' : 'right')),
            $event->is('scrollup') => $this->handleKey(new Key('left')),
            $event->is('scrolldown') => $this->handleKey(new Key('right')),
            default => false,
        };
    }

    public function handleKey(Key $key): bool
    {
        $options = ($this->options)();
        if (!count($options)) {
            return false;
        }
        $index = array_search($this->value, $options, true);
        $index = $index === false ? 0 : $index;

        if ($key->is('left')) {
            $index = ($index - 1 + count($options)) % count($options);
        } elseif ($key->is('right') || $key->is('enter') || $key->isChar(' ')) {
            $index = ($index + 1) % count($options);
        } else {
            return false;
        }

        $this->value = $options[$index];
        if ($this->onChange) {
            ($this->onChange)($this->value);
        }

        return true;
    }
}
