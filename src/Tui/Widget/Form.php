<?php

namespace App\Tui\Widget;

use App\Tui\Canvas;
use App\Tui\Terminal\Key;

/**
 * Holds widgets in focus order and routes keys to the focused one.
 * Tab / shift+tab always move focus, up / down move focus unless the focused widget uses them.
 */
final class Form
{
    /** @var list<Widget> */
    private array $widgets = [];

    private int $focus = 0;

    /**
     * @template T of Widget
     *
     * @param T $widget
     *
     * @return T
     */
    public function add(Widget $widget): Widget
    {
        $this->widgets[] = $widget;

        return $widget;
    }

    public function render(Canvas $canvas, bool $active): void
    {
        $this->ensureFocusable();
        foreach ($this->widgets as $index => $widget) {
            $widget->render($canvas, $active && $index === $this->focus);
        }
    }

    public function getFocused(): ?Widget
    {
        $this->ensureFocusable();

        return $this->widgets[$this->focus] ?? null;
    }

    public function focus(Widget $widget): void
    {
        $index = array_search($widget, $this->widgets, true);
        if ($index !== false) {
            $this->focus = $index;
        }
    }

    /**
     * Routes a mouse event to the widget under the pointer (focusing it on click).
     * Scroll events go to the widget under the pointer, or the focused one.
     */
    public function handleMouse(Key $event): bool
    {
        foreach ($this->widgets as $widget) {
            if (!$widget->visible || !$widget->contains($event->x, $event->y)) {
                continue;
            }
            if (!$event->isScroll() && !$widget->isFocusable()) {
                return true; // disabled widget, swallow the click
            }
            if ($event->is('click', 'doubleclick')) {
                $this->focus($widget);
            }

            return $widget->handleMouse($event) || true;
        }

        if ($event->isScroll()) {
            return (bool) $this->getFocused()?->handleMouse($event);
        }

        return false;
    }

    public function handleKey(Key $key): bool
    {
        $focused = $this->getFocused();
        if ($focused === null) {
            return false;
        }

        if ($key->is('tab')) {
            return $this->move(1);
        }
        if ($key->is('backtab')) {
            return $this->move(-1);
        }

        if ($focused->handleKey($key)) {
            return true;
        }

        if ($key->is('down') || ($key->is('right') && $focused instanceof Button)) {
            return $this->move(1, wrap: false);
        }
        if ($key->is('up') || ($key->is('left') && $focused instanceof Button)) {
            return $this->move(-1, wrap: false);
        }

        return false;
    }

    public function focusFirst(): void
    {
        $this->focus = 0;
        $this->ensureFocusable();
    }

    private function move(int $direction, bool $wrap = true): bool
    {
        $count = count($this->widgets);
        if ($count === 0) {
            return false;
        }

        $index = $this->focus;
        for ($i = 0; $i < $count; ++$i) {
            $index += $direction;
            if ($index < 0 || $index >= $count) {
                if (!$wrap) {
                    return false;
                }
                $index = ($index + $count) % $count;
            }
            if ($this->widgets[$index]->isFocusable()) {
                $this->focus = $index;

                return true;
            }
        }

        return false;
    }

    private function ensureFocusable(): void
    {
        if (isset($this->widgets[$this->focus]) && $this->widgets[$this->focus]->isFocusable()) {
            return;
        }
        foreach ($this->widgets as $index => $widget) {
            if ($widget->isFocusable()) {
                $this->focus = $index;

                return;
            }
        }
    }
}
