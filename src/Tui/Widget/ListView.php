<?php

namespace App\Tui\Widget;

use App\Tui\Canvas;
use App\Tui\Terminal\Key;
use App\Tui\Theme;
use Closure;

/**
 * A scrollable list. Rows are drawn by a callback: fn (Canvas, int $index, int $x, int $y, int $width, bool $isCursor, bool $focused).
 */
final class ListView extends Widget
{
    public int $cursor = 0;

    public int $offset = 0;

    public int $width = 10;

    public int $height = 5;

    /** @var Closure(): int */
    private Closure $count;

    public function __construct(
        Closure $count,
        private readonly Closure $renderRow,
        private readonly ?Closure $onActivate = null,
        private readonly ?Closure $onKey = null,
        public string $emptyText = '(empty)',
        public bool $followTail = false,
        private readonly ?Closure $onClick = null,
    ) {
        $this->count = $count;
    }

    public function contains(int $x, int $y): bool
    {
        // includes the scrollbar column
        return $this->visible && $y >= $this->y && $y < $this->y + $this->height && $x >= $this->x && $x <= $this->x + $this->width;
    }

    public function handleMouse(Key $event): bool
    {
        $count = $this->count();
        if ($event->isScroll()) {
            $this->scrollBy($event->is('scrollup') ? -3 : 3, $count);

            return true;
        }
        if ($count === 0 || !$event->is('click', 'doubleclick')) {
            return false;
        }

        // scrollbar: jump to the clicked position
        if ($event->x === $this->x + $this->width && $count > $this->height) {
            $fraction = ($event->y - $this->y) / max(1, $this->height - 1);
            $this->offset = (int) round($fraction * ($count - $this->height));
            $this->scrollBy(0, $count);

            return true;
        }

        $index = $this->offset + ($event->y - $this->y);
        if ($index >= $count) {
            return true;
        }
        $this->cursor = $index;

        if ($event->is('click') && $this->onClick) {
            ($this->onClick)($index, $event->x - $this->x);
        } elseif ($event->is('doubleclick') && $this->onActivate) {
            ($this->onActivate)($index);
        }

        return true;
    }

    public function count(): int
    {
        return ($this->count)();
    }

    public function render(Canvas $canvas, bool $focused): void
    {
        if (!$this->visible) {
            return;
        }

        $count = $this->count();
        if ($this->followTail && $count > 0 && $this->cursor >= $count - 2) {
            $this->cursor = $count - 1;
        }
        $this->clamp($count);

        if ($count === 0) {
            $canvas->text($this->x + 1, $this->y, $this->emptyText, Theme::DIM, $this->width - 1);

            return;
        }

        for ($row = 0; $row < $this->height; ++$row) {
            $index = $this->offset + $row;
            if ($index >= $count) {
                break;
            }
            $isCursor = $index === $this->cursor;
            if ($isCursor) {
                $canvas->fill($this->x, $this->y + $row, $this->width, 1, $focused ? Theme::LIST_CURSOR : Theme::LIST_CURSOR_UNFOCUSED);
            }
            ($this->renderRow)($canvas, $index, $this->x, $this->y + $row, $this->width, $isCursor, $focused);
        }

        // scrollbar
        if ($count > $this->height && $this->height > 2) {
            $thumbSize = max(1, (int) floor($this->height * $this->height / $count));
            $thumbPosition = (int) floor(($this->height - $thumbSize) * $this->offset / max(1, $count - $this->height));
            for ($row = 0; $row < $this->height; ++$row) {
                $isThumb = $row >= $thumbPosition && $row < $thumbPosition + $thumbSize;
                $canvas->text($this->x + $this->width, $this->y + $row, $isThumb ? '█' : '░', Theme::DIM);
            }
        }
    }

    public function handleKey(Key $key): bool
    {
        $count = $this->count();
        if ($this->onKey && ($this->onKey)($key, $this->cursor)) {
            return true;
        }
        if ($count === 0) {
            return false;
        }

        switch (true) {
            case $key->is('up'):
                if ($this->cursor === 0) {
                    return false; // let focus move to the previous widget
                }
                --$this->cursor;
                break;
            case $key->is('down'):
                if ($this->cursor >= $count - 1) {
                    return false;
                }
                ++$this->cursor;
                break;
            case $key->is('pageup'):
                $this->cursor -= max(1, $this->height - 1);
                break;
            case $key->is('pagedown'):
                $this->cursor += max(1, $this->height - 1);
                break;
            case $key->is('home'):
                $this->cursor = 0;
                break;
            case $key->is('end'):
                $this->cursor = $count - 1;
                break;
            case $key->is('enter'):
                if ($this->onActivate) {
                    ($this->onActivate)($this->cursor);

                    return true;
                }

                return false;
            default:
                return false;
        }

        $this->clamp($count);

        return true;
    }

    public function capturesVerticalKeys(): bool
    {
        return true;
    }

    /**
     * Scrolls the view, keeping the cursor inside the visible rows.
     */
    private function scrollBy(int $rows, int $count): void
    {
        $this->offset = max(0, min($this->offset + $rows, max(0, $count - $this->height)));
        if ($this->cursor < $this->offset) {
            $this->cursor = $this->offset;
        } elseif ($this->cursor >= $this->offset + $this->height) {
            $this->cursor = $this->offset + $this->height - 1;
        }
    }

    private function clamp(int $count): void
    {
        $this->cursor = max(0, min($this->cursor, $count - 1));
        if ($this->cursor < $this->offset) {
            $this->offset = $this->cursor;
        }
        if ($this->cursor >= $this->offset + $this->height) {
            $this->offset = $this->cursor - $this->height + 1;
        }
        $this->offset = max(0, min($this->offset, max(0, $count - $this->height)));
    }
}
