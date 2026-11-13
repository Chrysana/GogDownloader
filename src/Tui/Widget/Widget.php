<?php

namespace App\Tui\Widget;

use App\Tui\Canvas;
use App\Tui\Terminal\Key;

abstract class Widget
{
    public int $x = 0;

    public int $y = 0;

    public bool $visible = true;

    public bool $enabled = true;

    abstract public function render(Canvas $canvas, bool $focused): void;

    /**
     * @return bool whether the key was handled
     */
    public function handleKey(Key $key): bool
    {
        return false;
    }

    /**
     * Whether the cell at the given screen position belongs to this widget (as drawn by the last render).
     */
    public function contains(int $x, int $y): bool
    {
        return false;
    }

    /**
     * Handles a mouse event (see Key::MOUSE_EVENTS) located inside this widget.
     *
     * @return bool whether the event was handled
     */
    public function handleMouse(Key $event): bool
    {
        return $event->is('click', 'doubleclick') && $this->handleKey(new Key('enter'));
    }

    public function isFocusable(): bool
    {
        return $this->visible && $this->enabled;
    }

    /**
     * Whether the widget uses up/down keys itself (otherwise they move focus).
     */
    public function capturesVerticalKeys(): bool
    {
        return false;
    }

    /**
     * Whether the widget consumes printable characters (so they don't trigger page hotkeys).
     */
    public function capturesText(): bool
    {
        return false;
    }
}
