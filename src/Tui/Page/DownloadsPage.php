<?php

namespace App\Tui\Page;

use App\Tui\App;
use App\Tui\Canvas;
use App\Tui\Theme;
use App\Tui\Widget\Button;

final class DownloadsPage extends Page
{
    private Button $cancel;

    private Button $library;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->cancel = $this->form->add(new Button('Cancel', $this->cancelJob(...)));
        $this->library = $this->form->add(new Button('Go to Library', fn () => $this->app->selectPage(LibraryPage::class, focusContent: true)));
    }

    public function getMenuLabel(): string
    {
        return 'Downloads';
    }

    public function render(Canvas $canvas, int $x, int $y, int $width, int $height, bool $focused): void
    {
        $this->heading($canvas, $x, $y, $width, 'Downloads');
        $job = $this->app->getJob();
        $row = $y + 2;

        if ($job === null || $job->type !== 'download') {
            $canvas->text($x, $row, 'No download has been started in this session.', Theme::TEXT, $width);
            $canvas->text($x, $row + 2, 'Select games on the Game Library page and choose "Download" (or press D).', Theme::DIM, $width);
            $canvas->text($x, $row + 3, 'With nothing selected, all games matching the current filters are downloaded.', Theme::DIM, $width);
            $canvas->text($x, $row + 5, 'Destination: ', Theme::LABEL);
            $canvas->text($x + 13, $row + 5, $this->app->getDownloadDirectory(), Theme::VALUE, $width - 13);
            $this->cancel->visible = false;
            $this->library->x = $x;
            $this->library->y = $row + 7;
            $this->form->render($canvas, $focused);

            return;
        }

        $row += JobPanel::render($canvas, $job, $x, $row, $width) + 1;

        $this->cancel->visible = $job->isRunning();
        $this->cancel->label = 'Cancel Download';
        $this->cancel->x = $x;
        $this->cancel->y = $row;
        $this->library->x = $job->isRunning() ? $x + $this->cancel->getWidth() + 2 : $x;
        $this->library->y = $row;
        $row += 2;

        $canvas->text($x, $row, 'Recent files', Theme::LIST_HEADER);
        JobPanel::results($canvas, $job, $x, $row + 1, $width, $y + $height - $row - 1);

        $this->form->render($canvas, $focused);
    }

    private function cancelJob(): void
    {
        $job = $this->app->getJob();
        if (!$job?->isRunning()) {
            return;
        }
        $this->app->confirm('Cancel', ['Cancel the running download?', '', 'The partially downloaded file will be resumed next time.'], fn () => $job->cancel(), 'Cancel Download', 'Keep Going');
    }
}
