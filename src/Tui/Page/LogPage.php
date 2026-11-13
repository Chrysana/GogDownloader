<?php

namespace App\Tui\Page;

use App\Tui\App;
use App\Tui\Canvas;
use App\Tui\Theme;
use App\Tui\Widget\Button;
use App\Tui\Widget\ListView;

final class LogPage extends Page
{
    private ListView $list;

    private Button $clear;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->list = $this->form->add(new ListView(
            count: fn () => count($this->app->getLog()),
            renderRow: function (Canvas $canvas, int $index, int $x, int $y, int $width, bool $isCursor, bool $focused) {
                [$time, $message] = $this->app->getLog()[$index];
                $style = match (true) {
                    $isCursor => $focused ? Theme::LIST_CURSOR : Theme::LIST_CURSOR_UNFOCUSED,
                    (bool) preg_match('/\b(error|failed|exception)\b/i', $message) => Theme::BAD,
                    (bool) preg_match('/\b(warning|note)\b/i', $message) => Theme::WARN,
                    default => Theme::TEXT,
                };
                $canvas->text($x, $y, $time, $isCursor ? $style : Theme::DIM);
                $canvas->text($x + 9, $y, $message, $style, $width - 9);
            },
            emptyText: 'Nothing logged yet.',
            followTail: true,
        ));
        $this->clear = $this->form->add(new Button('Clear Log', fn () => $this->app->clearLog()));
    }

    public function getMenuLabel(): string
    {
        return 'Activity Log';
    }

    public function getHints(): string
    {
        return '↑↓ PgUp PgDn Home End scroll · Tab buttons · Esc menu';
    }

    public function onShow(): void
    {
        $this->list->cursor = PHP_INT_MAX; // jump to the newest entry
    }

    public function render(Canvas $canvas, int $x, int $y, int $width, int $height, bool $focused): void
    {
        $this->heading($canvas, $x, $y, $width, 'Activity Log');
        $this->list->x = $x;
        $this->list->y = $y + 2;
        $this->list->width = $width - 1;
        $this->list->height = max(3, $height - 5);

        $this->clear->x = $x;
        $this->clear->y = $this->list->y + $this->list->height + 1;

        $this->form->render($canvas, $focused);
    }
}
