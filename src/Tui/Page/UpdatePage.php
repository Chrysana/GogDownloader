<?php

namespace App\Tui\Page;

use App\Tui\App;
use App\Tui\Canvas;
use App\Tui\Theme;
use App\Tui\Widget\Button;
use App\Tui\Widget\Checkbox;
use App\Tui\Widget\RadioGroup;

final class UpdatePage extends Page
{
    private RadioGroup $mode;

    private Checkbox $includeHidden;

    private Checkbox $removeHidden;

    private Button $start;

    private Button $refresh;

    private Button $cancel;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->mode = $this->form->add(new RadioGroup([
            'updated' => 'New and updated games only (fast)',
            'new' => 'New games only',
            'all' => 'All games',
            'clear' => 'Rebuild from scratch (clears the local database first)',
        ], 'updated', vertical: true));
        $this->includeHidden = $this->form->add(new Checkbox('Include games hidden in your GOG library'));
        $this->removeHidden = $this->form->add(new Checkbox('Remove hidden games from the local database', true));
        $this->start = $this->form->add(new Button('Start Update', $this->startUpdate(...)));
        $this->refresh = $this->form->add(new Button('Refresh Library List Only', fn () => $this->app->refreshLibrary()));
        $this->cancel = $this->form->add(new Button('Cancel Update', function () {
            $this->app->confirm('Cancel', 'Cancel the running update?', fn () => $this->app->getJob()?->cancel(), 'Cancel Update', 'Keep Going');
        }));
    }

    public function getMenuLabel(): string
    {
        return 'Update Database';
    }

    public function render(Canvas $canvas, int $x, int $y, int $width, int $height, bool $focused): void
    {
        $this->heading($canvas, $x, $y, $width, 'Update Local Database');
        $row = $y + 2;

        $canvas->text($x, $row, 'Fetches the file lists (installers, patches, extras) of your games from GOG.', Theme::DIM, $width);
        $canvas->text($x, $row + 1, 'The library list (hidden status and tags) is refreshed as well.', Theme::DIM, $width);
        $row += 3;

        $canvas->text($x, $row, 'Which games', Theme::LIST_HEADER);
        $this->mode->x = $x + 2;
        $this->mode->y = $row + 1;
        $row += $this->mode->getHeight() + 2;

        $canvas->text($x, $row, 'Hidden games', Theme::LIST_HEADER);
        $this->includeHidden->x = $x + 2;
        $this->includeHidden->y = $row + 1;
        $this->removeHidden->x = $x + 2;
        $this->removeHidden->y = $row + 2;
        $this->removeHidden->enabled = !$this->includeHidden->checked;
        $row += 4;

        $running = $this->app->isJobRunning();
        $this->start->enabled = !$running;
        $this->refresh->enabled = !$running;
        $this->start->x = $x;
        $this->start->y = $row;
        $this->refresh->x = $x + $this->start->getWidth() + 2;
        $this->refresh->y = $row;
        $job = $this->app->getJob();
        $this->cancel->visible = $running && $job?->type === 'update';
        $this->cancel->x = $this->refresh->x + $this->refresh->getWidth() + 2;
        $this->cancel->y = $row;
        $row += 2;

        if ($job !== null && $job->type === 'update') {
            $canvas->fill($x, $row, $width, 1, Theme::BASE, '─');
            $row += 1;
            $row += JobPanel::render($canvas, $job, $x, $row, $width) + 1;
            $remaining = $y + $height - $row;
            if ($remaining > 1) {
                JobPanel::results($canvas, $job, $x, $row, $width, $remaining);
            }
        }

        $this->form->render($canvas, $focused);
    }

    private function startUpdate(): void
    {
        $arguments = ['update-database', '--json-progress', '--skip-errors'];
        match ($this->mode->value) {
            'updated' => $arguments[] = '--updated-only',
            'new' => $arguments[] = '--new-only',
            'clear' => $arguments[] = '--clear',
            default => null,
        };
        if ($this->includeHidden->checked) {
            $arguments[] = '--include-hidden';
        } elseif ($this->removeHidden->checked) {
            $arguments[] = '--remove-hidden';
        }

        if (!$this->app->isLoggedIn()) {
            $this->app->showError('Not logged in', 'Please log in on the Account page first.');

            return;
        }
        $start = fn () => $this->app->startJob('Update database', 'update', $arguments);
        if ($this->mode->value === 'clear') {
            $this->app->confirm('Rebuild', 'This deletes all locally stored game information and fetches it again, which can take a long time. Continue?', $start);

            return;
        }
        $start();
    }
}
