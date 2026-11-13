<?php

namespace App\Tui\Page;

use App\Tui\App;
use App\Tui\Canvas;
use App\Tui\Terminal\Key;
use App\Tui\Widget\Form;

abstract class Page
{
    protected Form $form;

    public function __construct(
        protected readonly App $app,
    ) {
        $this->form = new Form();
    }

    abstract public function getMenuLabel(): string;

    abstract public function render(Canvas $canvas, int $x, int $y, int $width, int $height, bool $focused): void;

    /**
     * @return string short key hints displayed in the status bar
     */
    public function getHints(): string
    {
        return 'Tab/↑↓ move · Enter/Space activate · Esc menu';
    }

    public function handleKey(Key $key): bool
    {
        return $this->form->handleKey($key);
    }

    public function handleMouse(Key $event): bool
    {
        return $this->form->handleMouse($event);
    }

    /**
     * Whether the focused element consumes typed characters (disables single-key shortcuts).
     */
    public function capturesText(): bool
    {
        return (bool) $this->form->getFocused()?->capturesText();
    }

    public function onShow(): void
    {
    }

    protected function heading(Canvas $canvas, int $x, int $y, int $width, string $text): void
    {
        $canvas->centered($x, $y, $width, $text, \App\Tui\Theme::HEADING);
    }
}
