<?php

namespace App\Tui;

use App\Tui\Terminal\Key;
use App\Tui\Widget\Button;
use App\Tui\Widget\Form;
use Closure;

/**
 * A dialog box drawn over the interface: a message (scrollable if long) and a row of buttons.
 */
final class Modal
{
    private Form $form;

    /** @var list<Button> */
    private array $buttons = [];

    private int $scroll = 0;

    private int $visibleLines = 1;

    /**
     * @param list<string|array{string, string}> $lines   plain strings or [text, style] pairs
     * @param array<string, Closure|null>        $buttons label => callback (the dialog closes before the callback runs)
     */
    public function __construct(
        private readonly App $app,
        public string $title,
        public array $lines,
        array $buttons = ['OK' => null],
        private readonly ?Closure $onEscape = null,
        public ?int $preferredWidth = null,
    ) {
        $this->form = new Form();
        foreach ($buttons as $label => $callback) {
            $this->buttons[] = $this->form->add(new Button($label, function () use ($callback) {
                $this->app->closeModal($this);
                if ($callback) {
                    $callback();
                }
            }));
        }
    }

    public function render(Canvas $canvas): void
    {
        $maxWidth = max(20, $canvas->width - 6);
        $contentWidth = $this->preferredWidth ?? 0;
        foreach ($this->lines as $line) {
            $contentWidth = max($contentWidth, Canvas::width(is_array($line) ? $line[0] : $line));
        }
        $buttonsWidth = array_sum(array_map(fn (Button $button) => $button->getWidth() + 2, $this->buttons));
        $width = min($maxWidth, max($contentWidth + 4, $buttonsWidth + 4, Canvas::width($this->title) + 8, 30));

        $maxHeight = max(6, $canvas->height - 4);
        $height = min($maxHeight, count($this->lines) + 5);
        $this->visibleLines = $height - 5;
        $this->scroll = max(0, min($this->scroll, count($this->lines) - $this->visibleLines));

        $x = intdiv($canvas->width - $width, 2);
        $y = intdiv($canvas->height - $height, 2);

        $canvas->fill($x + 1, $y + 1, $width, $height, '30;40'); // shadow
        $canvas->fill($x, $y, $width, $height, Theme::MODAL);
        $canvas->box($x, $y, $width, $height, Theme::MODAL_BORDER, $this->title);

        for ($i = 0; $i < $this->visibleLines; ++$i) {
            $line = $this->lines[$this->scroll + $i] ?? null;
            if ($line === null) {
                break;
            }
            [$text, $style] = is_array($line) ? $line : [$line, Theme::MODAL_TEXT];
            $canvas->text($x + 2, $y + 2 + $i, $text, $style, $width - 4);
        }
        if (count($this->lines) > $this->visibleLines) {
            $canvas->text($x + $width - 12, $y + $height - 1, sprintf(' %d/%d ', $this->scroll + 1, max(1, count($this->lines) - $this->visibleLines + 1)), Theme::MODAL_BORDER);
        }

        $buttonX = $x + intdiv($width - $buttonsWidth + 2, 2);
        foreach ($this->buttons as $button) {
            $button->x = $buttonX;
            $button->y = $y + $height - 2;
            $buttonX += $button->getWidth() + 2;
        }
        $this->form->render($canvas, true);
    }

    public function handleMouse(Key $event): void
    {
        if ($event->is('scrollup')) {
            $this->scroll = max(0, $this->scroll - 3);

            return;
        }
        if ($event->is('scrolldown')) {
            $this->scroll += 3;

            return;
        }
        $this->form->handleMouse($event);
    }

    public function handleKey(Key $key): void
    {
        if ($key->is('esc')) {
            $this->app->closeModal($this);
            if ($this->onEscape) {
                ($this->onEscape)();
            }

            return;
        }
        if ($key->is('up')) {
            $this->scroll = max(0, $this->scroll - 1);

            return;
        }
        if ($key->is('down')) {
            ++$this->scroll;

            return;
        }
        if ($key->is('pageup')) {
            $this->scroll = max(0, $this->scroll - $this->visibleLines);

            return;
        }
        if ($key->is('pagedown')) {
            $this->scroll += $this->visibleLines;

            return;
        }
        if ($key->is('left')) {
            $key = new Key('backtab');
        } elseif ($key->is('right')) {
            $key = new Key('tab');
        }
        $this->form->handleKey($key);
    }
}
