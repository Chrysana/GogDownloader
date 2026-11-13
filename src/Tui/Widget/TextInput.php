<?php

namespace App\Tui\Widget;

use App\Tui\Canvas;
use App\Tui\Terminal\Key;
use App\Tui\Theme;
use Closure;

final class TextInput extends Widget
{
    private int $cursor;

    private int $scroll = 0;

    public function __construct(
        public int $width,
        public string $value = '',
        private readonly ?Closure $onChange = null,
        private readonly ?Closure $onSubmit = null,
        public bool $masked = false,
        public string $placeholder = '',
    ) {
        $this->cursor = mb_strlen($value);
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
        $this->cursor = mb_strlen($value);
        $this->scroll = 0;
    }

    public function render(Canvas $canvas, bool $focused): void
    {
        if (!$this->visible) {
            return;
        }

        $style = $focused ? Theme::INPUT_FOCUSED : Theme::INPUT;
        $display = $this->masked ? str_repeat('*', mb_strlen($this->value)) : $this->value;
        $inner = max(1, $this->width - 1);

        // keep the cursor visible
        if ($this->cursor < $this->scroll) {
            $this->scroll = $this->cursor;
        } elseif ($this->cursor - $this->scroll >= $inner) {
            $this->scroll = $this->cursor - $inner + 1;
        }
        $visible = mb_substr($display, $this->scroll, $inner);

        $canvas->fill($this->x, $this->y, $this->width, 1, $style);
        if ($visible === '' && !$focused && $this->placeholder !== '') {
            $canvas->text($this->x, $this->y, $this->placeholder, Theme::INPUT_PLACEHOLDER, $this->width);
        } else {
            $canvas->text($this->x, $this->y, $visible, $style, $this->width);
        }

        if ($focused) {
            $before = mb_substr($display, $this->scroll, $this->cursor - $this->scroll);
            $canvas->setCursor($this->x + Canvas::width($before), $this->y);
        }
    }

    public function contains(int $x, int $y): bool
    {
        return $this->visible && $y === $this->y && $x >= $this->x && $x < $this->x + $this->width;
    }

    public function handleMouse(Key $event): bool
    {
        if ($event->is('click')) {
            // place the cursor where the field was clicked
            $display = $this->masked ? str_repeat('*', mb_strlen($this->value)) : $this->value;
            $target = $event->x - $this->x;
            $position = $this->scroll;
            $width = 0;
            $length = mb_strlen($display);
            while ($position < $length) {
                $charWidth = Canvas::width(mb_substr($display, $position, 1));
                if ($width + $charWidth > $target) {
                    break;
                }
                $width += $charWidth;
                ++$position;
            }
            $this->cursor = $position;

            return true;
        }
        if ($event->is('doubleclick')) {
            $this->cursor = mb_strlen($this->value);

            return true;
        }

        return false;
    }

    public function handleKey(Key $key): bool
    {
        $length = mb_strlen($this->value);

        switch (true) {
            case $key->isChar():
                $this->value = mb_substr($this->value, 0, $this->cursor) . $key->char . mb_substr($this->value, $this->cursor);
                ++$this->cursor;
                $this->changed();

                return true;
            case $key->is('backspace'):
                if ($this->cursor > 0) {
                    $this->value = mb_substr($this->value, 0, $this->cursor - 1) . mb_substr($this->value, $this->cursor);
                    --$this->cursor;
                    $this->changed();
                }

                return true;
            case $key->is('delete'):
                if ($this->cursor < $length) {
                    $this->value = mb_substr($this->value, 0, $this->cursor) . mb_substr($this->value, $this->cursor + 1);
                    $this->changed();
                }

                return true;
            case $key->is('left'):
                $this->cursor = max(0, $this->cursor - 1);

                return true;
            case $key->is('right'):
                $this->cursor = min($length, $this->cursor + 1);

                return true;
            case $key->is('home', 'ctrl+a'):
                $this->cursor = 0;

                return true;
            case $key->is('end', 'ctrl+e'):
                $this->cursor = $length;

                return true;
            case $key->is('ctrl+u'):
                $this->setValue('');
                $this->changed();

                return true;
            case $key->is('enter'):
                if ($this->onSubmit) {
                    ($this->onSubmit)($this->value);

                    return true;
                }

                return false;
        }

        return false;
    }

    public function capturesText(): bool
    {
        return true;
    }

    private function changed(): void
    {
        if ($this->onChange) {
            ($this->onChange)($this->value);
        }
    }
}
