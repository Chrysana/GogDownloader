<?php

namespace App\Tui\Page;

use App\Tui\App;
use App\Tui\Canvas;
use App\Tui\Job;
use App\Tui\Theme;

/**
 * Draws the progress of a job: overall progress, current item, current file with speed and ETA.
 */
final class JobPanel
{
    /**
     * @return int number of rows used
     */
    public static function render(Canvas $canvas, Job $job, int $x, int $y, int $width): int
    {
        $row = $y;
        $labelWidth = 14;
        $barWidth = max(10, $width - $labelWidth - 12);

        $state = match (true) {
            $job->isRunning() => ['running', Theme::WARN],
            $job->cancelled => ['cancelled', Theme::BAD],
            $job->succeeded() => ['finished', Theme::GOOD],
            default => ['failed', Theme::BAD],
        };
        $canvas->text($x, $row, Canvas::fit('Task:', $labelWidth), Theme::LABEL);
        $written = $canvas->text($x + $labelWidth, $row, $job->title, Theme::VALUE, $width - $labelWidth - 14);
        $canvas->text($x + $labelWidth + $written + 2, $row, "( {$state[0]} )", $state[1]);
        $row += 2;

        // overall
        $total = $job->totalGames;
        $done = $total === null ? 0 : ($job->isRunning() ? max(0, $job->gameIndex) : $total);
        $canvas->text($x, $row, Canvas::fit('Games:', $labelWidth), Theme::LABEL);
        self::bar($canvas, $x + $labelWidth, $row, $barWidth, $total ? $done / max(1, $total) : 0);
        $canvas->text($x + $labelWidth + $barWidth + 1, $row, $total === null ? '…' : sprintf('%d / %d', min($done + ($job->isRunning() ? 1 : 0), $total), $total), Theme::VALUE);
        ++$row;
        $canvas->text($x, $row, Canvas::fit('Current:', $labelWidth), Theme::LABEL);
        $canvas->text($x + $labelWidth, $row, $job->isRunning() ? ($job->currentGame ?? 'preparing…') : '-', Theme::VALUE, $width - $labelWidth);
        $row += 2;

        if ($job->type === 'download') {
            $canvas->text($x, $row, Canvas::fit('File:', $labelWidth), Theme::LABEL);
            $canvas->text($x + $labelWidth, $row, $job->currentFile ?? ($job->isRunning() ? 'checking files…' : '-'), Theme::VALUE, $width - $labelWidth);
            ++$row;
            $fraction = $job->currentFile !== null && $job->fileTotal > 0 ? $job->fileCurrent / $job->fileTotal : 0;
            self::bar($canvas, $x + $labelWidth, $row, $barWidth, $fraction);
            $canvas->text($x + $labelWidth + $barWidth + 1, $row, sprintf('%3d%%', $fraction * 100), Theme::VALUE);
            ++$row;
            if ($job->currentFile !== null && $job->fileTotal > 0) {
                $speed = $job->getSpeed();
                $eta = $speed > 0 ? App::formatDuration(($job->fileTotal - $job->fileCurrent) / $speed) : '--:--';
                $canvas->text($x + $labelWidth, $row, sprintf(
                    '%s / %s · %s/s · ETA %s',
                    App::formatBytes($job->fileCurrent),
                    App::formatBytes($job->fileTotal),
                    App::formatBytes($speed),
                    $eta,
                ), Theme::DIM, $width - $labelWidth);
            }
            $row += 2;
            if ($job->testRun) {
                $canvas->text($x, $row, Canvas::fit('Test run:', $labelWidth), Theme::LABEL);
                $written = $canvas->text($x + $labelWidth, $row, sprintf('%d file(s) would be downloaded (%s)', $job->testFiles, App::formatBytes($job->testBytes)), Theme::WARN);
                $canvas->text($x + $labelWidth + $written, $row, sprintf(' · %d up to date · %d failed', $job->filesSkipped, $job->filesFailed), $job->filesFailed ? Theme::BAD : Theme::TEXT);
                ++$row;
                $canvas->text($x + $labelWidth, $row, 'Nothing is downloaded. Target paths are listed in the Activity Log.', Theme::DIM, $width - $labelWidth);
                ++$row;

                return $row - $y;
            }
            $canvas->text($x, $row, Canvas::fit('Files:', $labelWidth), Theme::LABEL);
            $written = $canvas->text($x + $labelWidth, $row, sprintf('%d downloaded', $job->filesDone), Theme::GOOD);
            $written += $canvas->text($x + $labelWidth + $written, $row, sprintf(' · %d up to date', $job->filesSkipped), Theme::TEXT);
            $canvas->text($x + $labelWidth + $written, $row, sprintf(' · %d failed', $job->filesFailed), $job->filesFailed ? Theme::BAD : Theme::TEXT);
            ++$row;
            $canvas->text($x, $row, Canvas::fit('Transferred:', $labelWidth), Theme::LABEL);
            $canvas->text($x + $labelWidth, $row, App::formatBytes($job->bytesDownloaded) . ' in ' . App::formatDuration($job->getElapsed()), Theme::VALUE);
            ++$row;
        } else {
            $canvas->text($x, $row, Canvas::fit('Results:', $labelWidth), Theme::LABEL);
            $written = $canvas->text($x + $labelWidth, $row, sprintf('%d updated', $job->filesDone), Theme::GOOD);
            $canvas->text($x + $labelWidth + $written, $row, sprintf(' · %d failed', $job->filesFailed), $job->filesFailed ? Theme::BAD : Theme::TEXT);
            ++$row;
            $canvas->text($x, $row, Canvas::fit('Elapsed:', $labelWidth), Theme::LABEL);
            $canvas->text($x + $labelWidth, $row, App::formatDuration($job->getElapsed()), Theme::VALUE);
            ++$row;
        }

        return $row - $y;
    }

    public static function bar(Canvas $canvas, int $x, int $y, int $width, float $fraction): void
    {
        $fraction = max(0.0, min(1.0, $fraction));
        $filled = (int) round($width * $fraction);
        $canvas->fill($x, $y, $filled, 1, Theme::PROGRESS_DONE);
        $canvas->fill($x + $filled, $y, $width - $filled, 1, Theme::PROGRESS_TODO);
    }

    /**
     * Draws the list of finished items (most recent first).
     */
    public static function results(Canvas $canvas, Job $job, int $x, int $y, int $width, int $height): void
    {
        $results = array_reverse($job->results);
        for ($i = 0; $i < $height && isset($results[$i]); ++$i) {
            [$status, $game, $name] = $results[$i];
            [$label, $style] = match ($status) {
                'failed' => ['FAILED ', Theme::BAD],
                'skipped' => ['ok     ', Theme::DIM],
                'updated' => ['updated', Theme::GOOD],
                'would-download' => ['would ↓', Theme::WARN],
                'would-resume' => ['resume ', Theme::WARN],
                default => ['done   ', Theme::GOOD],
            };
            $written = $canvas->text($x, $y + $i, $label, $style);
            $text = $game !== '' && !str_starts_with($name, $game) ? "{$game} · {$name}" : $name;
            $canvas->text($x + $written + 1, $y + $i, $text, Theme::TEXT, $width - $written - 1);
        }
    }
}
