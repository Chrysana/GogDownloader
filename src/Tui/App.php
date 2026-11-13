<?php

namespace App\Tui;

use App\DTO\GameDetail;
use App\DTO\LibraryItem;
use App\Enum\Setting;
use App\Service\AuthenticationManager;
use App\Service\FolderMigrator;
use App\Service\OwnedItemsManager;
use App\Service\Persistence\PersistenceManager;
use App\Tui\Page\AccountPage;
use App\Tui\Page\DownloadsPage;
use App\Tui\Page\LibraryPage;
use App\Tui\Page\LogPage;
use App\Tui\Page\Page;
use App\Tui\Page\SettingsPage;
use App\Tui\Page\UpdatePage;
use App\Tui\Terminal\Console;
use App\Tui\Terminal\Key;
use Closure;
use Throwable;

/**
 * The interactive text interface: a menu on the left, the selected page on the right,
 * a title bar on top and a status bar at the bottom.
 */
final class App
{
    private const MENU_WIDTH = 22;

    private const MENU_X = 1;

    private const MENU_Y = 2;

    private const DOUBLE_CLICK_SECONDS = 0.4;

    private const MIN_WIDTH = 72;

    private const MIN_HEIGHT = 20;

    private const MAX_LOG = 2000;

    public readonly TuiSettings $settings;

    /** @var list<Page> */
    private array $pages;

    private int $selectedPage = 0;

    private bool $menuFocused = true;

    /** @var list<Modal> */
    private array $modals = [];

    /** @var list<string> */
    private array $previousRows = [];

    private ?array $previousSize = null;

    private bool $running = true;

    private bool $dirty = true;

    private ?Job $job = null;

    /** @var array{float, int, int}|null time, x, y of the last click */
    private ?array $lastClick = null;

    /** @var list<array{string, string}> time, message */
    private array $log = [];

    /** @var array<int, LibraryItem> */
    private array $library = [];

    /** @var array<int, GameDetail> */
    private array $details = [];

    private bool $loggedIn = false;

    private ?string $username = null;

    public function __construct(
        private readonly Console $console,
        public readonly OwnedItemsManager $ownedItemsManager,
        public readonly PersistenceManager $persistence,
        public readonly AuthenticationManager $authentication,
        public readonly FolderMigrator $folderMigrator,
        public readonly string $version,
        public readonly ?string $newVersion = null,
    ) {
        $this->settings = TuiSettings::load($this->persistence);
        $this->pages = [
            new LibraryPage($this),
            new DownloadsPage($this),
            new UpdatePage($this),
            new AccountPage($this),
            new SettingsPage($this),
            new LogPage($this),
        ];
    }

    public function run(): void
    {
        $this->console->enable();
        $restored = false;
        $restore = function () use (&$restored) {
            if ($restored) {
                return;
            }
            $restored = true;
            restore_error_handler();
            $this->job?->cancel();
            $this->console->setMouseEnabled(false);
            // leave alternate screen, show cursor, reset colors
            $this->console->write("\e[0m\e[?25h\e[?1049l");
            $this->console->restore();
        };
        register_shutdown_function($restore);

        // warnings and notices must not be printed over the interface, put them into the log instead
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            $this->log(sprintf('PHP warning: %s (%s:%d)', $message, basename($file), $line));

            return true;
        });

        try {
            $this->console->write("\e[?1049h\e[?25l\e[2J");
            $this->console->setMouseEnabled($this->settings->mouse);
            $this->reloadData();
            $this->pages[$this->selectedPage]->onShow();
            $this->log('GOG Downloader ' . $this->version . ' started, data directory: ' . ($_ENV['CONFIG_DIRECTORY'] ?? getcwd()));

            if (!$this->loggedIn) {
                $this->selectPage(AccountPage::class, focusContent: true);
                $this->showMessage('Welcome', [
                    "You're not logged in to GOG yet.",
                    '',
                    'Use the Account page to log in, then refresh your library.',
                ]);
            } elseif (!count($this->library) && !count($this->details)) {
                $this->showMessage('Welcome', [
                    'Your local library is empty.',
                    '',
                    'Press "Refresh Library" on the Library page (or R) to load your games.',
                ]);
            }
            if ($this->newVersion !== null) {
                $this->log("A new version ({$this->newVersion}) is available.");
            }

            $lastClock = '';
            while ($this->running) {
                $keys = $this->console->readKeys($this->job?->isRunning() ? 100 : 250);
                foreach ($keys as $key) {
                    $this->handleKey($key);
                    $this->dirty = true;
                }

                if ($this->job !== null && $this->job->poll()) {
                    $this->dirty = true;
                    if (!$this->job->isRunning()) {
                        $this->onJobFinished($this->job);
                    }
                }

                $clock = date('H:i');
                if ($clock !== $lastClock) {
                    $lastClock = $clock;
                    $this->dirty = true;
                }

                if ($this->dirty || $this->console->getSize() !== $this->previousSize) {
                    $this->render();
                    $this->dirty = false;
                }
            }
        } finally {
            $restore();
        }
    }

    // ---- data -------------------------------------------------------------------------------------------------

    public function reloadData(): void
    {
        $this->library = $this->ownedItemsManager->getLibrary();
        $this->details = [];
        foreach ($this->ownedItemsManager->getLocalGameData() as $detail) {
            $this->details[$detail->id] = $detail;
        }
        $this->loggedIn = $this->persistence->getAuthorization() !== null;
        foreach ($this->pages as $page) {
            if ($page instanceof LibraryPage) {
                $page->invalidate();
            }
        }
        $this->dirty = true;
    }

    /**
     * @return array<int, LibraryItem>
     */
    public function getLibrary(): array
    {
        return $this->library;
    }

    /**
     * @return array<int, GameDetail>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }

    public function refreshLibrary(): void
    {
        if (!$this->loggedIn) {
            $this->showError('Not logged in', 'Please log in on the Account page first.');

            return;
        }
        $this->busy('Loading your library from GOG...', function () {
            $library = $this->ownedItemsManager->refreshLibrary(httpTimeout: 15);
            $this->reloadData();
            $hidden = count(array_filter($library, fn (LibraryItem $item) => $item->hidden));
            $this->log(sprintf('Library refreshed: %d games, %d hidden, %d tags', count($library), $hidden, count($this->ownedItemsManager->getTagNames())));
        });
    }

    public function getDownloadDirectory(): string
    {
        $directory = $_ENV['DOWNLOAD_DIRECTORY']
            ?? $this->persistence->getSetting(Setting::DownloadPath)
            ?? getcwd();

        return (string) $directory;
    }

    /**
     * @return list<string> arguments for the download command based on the current settings
     */
    public function getDownloadArguments(): array
    {
        $settings = $this->settings;
        $arguments = ['download', '--json-progress', '--skip-errors'];

        if (count($settings->platforms) < 3) {
            foreach ($settings->platforms as $platform) {
                $arguments[] = "--os={$platform}";
            }
        }
        foreach (array_filter(array_map('trim', explode(',', $settings->languages))) as $language) {
            $arguments[] = "--language={$language}";
        }
        if ($settings->languages !== '' && $settings->englishFallback) {
            $arguments[] = '--language-fallback-english';
        }
        if (!$settings->installers) {
            $arguments[] = '--no-games';
        }
        if (!$settings->patches) {
            $arguments[] = '--no-patches';
        }
        if ($settings->extras) {
            $arguments[] = '--extras';
            if ($settings->skipExistingExtras) {
                $arguments[] = '--skip-existing-extras';
            }
        }
        if ($settings->removeInvalid) {
            $arguments[] = '--remove-invalid';
        }
        if ($settings->bandwidth !== '') {
            $arguments[] = '--bandwidth=' . preg_replace('/\s+/', '', $settings->bandwidth);
        }

        return $arguments;
    }

    // ---- jobs -------------------------------------------------------------------------------------------------

    public function getJob(): ?Job
    {
        return $this->job;
    }

    public function isJobRunning(): bool
    {
        return $this->job?->isRunning() ?? false;
    }

    /**
     * @param list<string>   $arguments
     * @param list<int>|null $gameIds   restrict the job to these games (passed using a temporary file)
     */
    public function startJob(string $title, string $type, array $arguments, ?array $gameIds = null): bool
    {
        if ($this->isJobRunning()) {
            $this->showError('Busy', 'Another operation is already running, wait for it to finish or cancel it first.');

            return false;
        }

        $temporaryFiles = [];
        if ($gameIds !== null) {
            $file = tempnam(sys_get_temp_dir(), 'gogdl');
            file_put_contents($file, implode("\n", $gameIds));
            $arguments[] = "--id-file={$file}";
            $temporaryFiles[] = $file;
        }

        try {
            $this->job = new Job($title, $type, $arguments, $this->log(...), $temporaryFiles);
        } catch (Throwable $e) {
            $this->showError('Failed to start', $e->getMessage());

            return false;
        }

        return true;
    }

    // ---- log --------------------------------------------------------------------------------------------------

    public function log(string $message): void
    {
        foreach (preg_split('/\R/', $message) as $line) {
            $this->log[] = [date('H:i:s'), $line];
        }
        if (count($this->log) > self::MAX_LOG) {
            $this->log = array_slice($this->log, -self::MAX_LOG);
        }
        $this->dirty = true;
    }

    /**
     * @return list<array{string, string}>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    public function clearLog(): void
    {
        $this->log = [];
    }

    // ---- modals -----------------------------------------------------------------------------------------------

    public function openModal(Modal $modal): void
    {
        $this->modals[] = $modal;
        $this->dirty = true;
    }

    public function closeModal(Modal $modal): void
    {
        $this->modals = array_values(array_filter($this->modals, fn (Modal $existing) => $existing !== $modal));
        $this->dirty = true;
    }

    /**
     * @param list<string|array{string, string}>|string $lines
     */
    public function showMessage(string $title, array|string $lines): void
    {
        $this->openModal(new Modal($this, $title, is_array($lines) ? $lines : self::wrap($lines, 60)));
    }

    public function showError(string $title, string $message): void
    {
        $this->log("Error: {$message}");
        $this->openModal(new Modal($this, $title, array_map(
            fn (string $line) => [$line, '1;97;44'],
            self::wrap($message, 60),
        )));
    }

    public function confirm(string $title, array|string $lines, Closure $onYes, string $yes = 'Yes', string $no = 'No'): void
    {
        $this->openModal(new Modal($this, $title, is_array($lines) ? $lines : self::wrap($lines, 60), [
            $yes => $onYes,
            $no => null,
        ]));
    }

    /**
     * Shows a "please wait" message, runs a blocking operation and reports errors.
     */
    public function busy(string $message, Closure $operation): void
    {
        $modal = new Modal($this, 'Please wait', [$message], []);
        $this->openModal($modal);
        $this->render();

        try {
            $operation();
        } catch (Throwable $e) {
            $this->closeModal($modal);
            $this->showError('Error', $e->getMessage());

            return;
        }
        $this->closeModal($modal);
    }

    // ---- navigation -------------------------------------------------------------------------------------------

    /**
     * @param class-string<Page> $class
     */
    public function selectPage(string $class, bool $focusContent = false): void
    {
        foreach ($this->pages as $index => $page) {
            if ($page instanceof $class) {
                $this->selectedPage = $index;
                $this->menuFocused = !$focusContent;
                $page->onShow();
                $this->dirty = true;

                return;
            }
        }
    }

    public function quit(): void
    {
        if ($this->isJobRunning()) {
            $this->confirm('Exit', [
                'An operation is still running:',
                $this->job->title,
                '',
                'Exit and cancel it? Partially downloaded files will be resumed next time.',
            ], function () {
                $this->job?->cancel();
                $this->running = false;
            });

            return;
        }
        $this->running = false;
    }

    public function getJobShortStatus(): string
    {
        $job = $this->job;
        if ($job === null) {
            return '';
        }
        if (!$job->isRunning()) {
            return $job->cancelled ? 'cancelled' : ($job->succeeded() ? 'finished' : 'failed');
        }
        $parts = [];
        if ($job->totalGames !== null) {
            $parts[] = sprintf('%d/%d', min($job->gameIndex + 1, $job->totalGames), $job->totalGames);
        }
        if ($job->currentFile !== null && $job->fileTotal > 0) {
            $parts[] = sprintf('%d%%', $job->fileCurrent / $job->fileTotal * 100);
        }
        if ($job->getSpeed() > 0) {
            $parts[] = self::formatBytes($job->getSpeed()) . '/s';
        }

        return implode(' · ', $parts) ?: 'starting...';
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    public static function formatBytes(float|int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            ++$unit;
        }

        return ($unit === 0 ? (string) (int) $bytes : number_format($bytes, $bytes >= 100 ? 0 : 1)) . ' ' . $units[$unit];
    }

    public static function formatDuration(float $seconds): string
    {
        $seconds = (int) round($seconds);
        if ($seconds >= 3600) {
            return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * @return list<string>
     */
    public static function wrap(string $text, int $width): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) as $paragraph) {
            $wrapped = wordwrap($paragraph, $width, "\n", true);
            foreach (explode("\n", $wrapped) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function setMouseEnabled(bool $enabled): void
    {
        $this->console->setMouseEnabled($enabled);
    }

    private function onJobFinished(Job $job): void
    {
        $this->reloadData();
        if ($job->cancelled) {
            return;
        }
        $lines = [
            $job->succeeded() ? [$job->title . ' finished.', Theme::MODAL_TEXT] : [$job->title . ' failed, see the Log page for details.', '1;93;44'],
        ];
        if ($job->type === 'download' && $job->testRun) {
            $lines[] = '';
            $lines[] = sprintf('Would download: %d file(s), %s', $job->testFiles, self::formatBytes($job->testBytes));
            $lines[] = sprintf('Already up to date: %d   Failed: %d', $job->filesSkipped, $job->filesFailed);
            $lines[] = '';
            $lines[] = 'Folders were created, nothing was downloaded.';
            $lines[] = 'The target path of every file is listed on the Activity Log page.';
        } elseif ($job->type === 'download') {
            $lines[] = '';
            $lines[] = sprintf('Downloaded: %d   Already up to date: %d   Failed: %d', $job->filesDone, $job->filesSkipped, $job->filesFailed);
        } elseif ($job->type === 'update') {
            $lines[] = '';
            $lines[] = sprintf('Updated: %d   Failed: %d', $job->filesDone, $job->filesFailed);
        }
        $this->showMessage($job->succeeded() ? 'Done' : 'Problem', $lines);
    }

    private function handleKey(Key $key): void
    {
        if ($key->is('ctrl+c')) {
            $this->quit();

            return;
        }

        if ($key->isMouse()) {
            $this->handleMouse($key);

            return;
        }

        if (count($this->modals)) {
            $this->modals[array_key_last($this->modals)]->handleKey($key);

            return;
        }

        if ($key->is('f1')) {
            $this->showHelp();

            return;
        }
        if ($key->is('f5')) {
            $this->reloadData();

            return;
        }

        // null when "Exit" is selected
        $page = $this->pages[$this->selectedPage] ?? null;

        if ($this->menuFocused || $page === null) {
            $this->menuFocused = true;
            $previousPage = $this->selectedPage;
            $count = count($this->pages) + 1; // + exit
            switch (true) {
                case $key->is('up'):
                    $this->selectedPage = ($this->selectedPage - 1 + $count) % $count;
                    break;
                case $key->is('down'):
                    $this->selectedPage = ($this->selectedPage + 1) % $count;
                    break;
                case $key->is('home'):
                    $this->selectedPage = 0;
                    break;
                case $key->is('end'):
                    $this->selectedPage = $count - 1;
                    break;
                case $key->is('enter', 'right', 'tab') || $key->isChar(' '):
                    if ($this->selectedPage === count($this->pages)) {
                        $this->quit();
                        break;
                    }
                    $this->menuFocused = false;
                    break;
                case $key->is('esc') || $key->isChar('q'):
                    $this->quit();
                    break;
                case $key->name === 'char' && ctype_digit($key->char) && (int) $key->char >= 1 && (int) $key->char <= $count:
                    $this->selectedPage = (int) $key->char - 1;
                    if ($this->selectedPage === count($this->pages)) {
                        $this->quit();
                    }
                    break;
            }
            if ($this->selectedPage !== $previousPage && isset($this->pages[$this->selectedPage])) {
                $this->pages[$this->selectedPage]->onShow();
            }

            return;
        }

        if ($page->handleKey($key)) {
            return;
        }

        if ($key->is('esc', 'backtab') || ($key->is('left') && !$page->capturesText())) {
            $this->menuFocused = true;
        }
    }

    private function handleMouse(Key $event): void
    {
        // terminals don't report double clicks, detect them from two quick clicks at the same position
        if ($event->is('click')) {
            $now = microtime(true);
            if ($this->lastClick !== null
                && $now - $this->lastClick[0] < self::DOUBLE_CLICK_SECONDS
                && $this->lastClick[1] === $event->x
                && $this->lastClick[2] === $event->y
            ) {
                $event = Key::mouse('doubleclick', $event->x, $event->y);
                $this->lastClick = null;
            } else {
                $this->lastClick = [$now, $event->x, $event->y];
            }
        }

        if (count($this->modals)) {
            $this->modals[array_key_last($this->modals)]->handleMouse($event);

            return;
        }

        // menu
        $menuIndex = $this->getMenuItemAt($event->x, $event->y);
        if ($menuIndex !== null) {
            if ($event->is('click', 'doubleclick')) {
                if ($menuIndex === count($this->pages)) {
                    $this->selectedPage = $menuIndex;
                    $this->menuFocused = true;
                    if ($event->is('click')) {
                        $this->quit();
                    }

                    return;
                }
                if ($menuIndex !== $this->selectedPage) {
                    $this->selectedPage = $menuIndex;
                    $this->pages[$menuIndex]->onShow();
                }
                // with the mouse it's more natural to continue on the page right away
                $this->menuFocused = false;
            } elseif ($event->isScroll()) {
                $count = count($this->pages);
                $this->selectedPage = ($this->selectedPage + ($event->is('scrollup') ? -1 : 1) + $count) % $count;
                $this->pages[$this->selectedPage]->onShow();
                $this->menuFocused = true;
            }

            return;
        }

        // content
        $page = $this->pages[$this->selectedPage] ?? null;
        if ($page === null || $event->x < self::MENU_WIDTH + 2) {
            return;
        }
        if ($page->handleMouse($event) && !$event->isScroll()) {
            $this->menuFocused = false;
        }
    }

    private function getMenuItemAt(int $x, int $y): ?int
    {
        if ($x < self::MENU_X || $x >= self::MENU_X + self::MENU_WIDTH || $y < self::MENU_Y) {
            return null;
        }
        $offset = $y - self::MENU_Y;
        if ($offset % 2 !== 0) {
            return null; // the gap between items
        }
        $index = intdiv($offset, 2);

        return $index <= count($this->pages) ? $index : null;
    }

    private function showHelp(): void
    {
        $this->showMessage('Help', [
            ['Navigation', Theme::MODAL_BORDER],
            '  ↑ ↓            move in the menu / between fields',
            '  Enter, →       open the selected page',
            '  Esc, ←         back to the menu',
            '  Tab, Shift+Tab next / previous field',
            '  Space, Enter   toggle checkbox, press button',
            '  1-7            jump to a menu item (in the menu)',
            '  F5             reload local data',
            '  Ctrl+C, q      exit',
            '  Mouse          click anything, wheel scrolls lists, double click opens details',
            '                 (can be turned off in Settings to select text with the mouse)',
            '',
            ['Library', Theme::MODAL_BORDER],
            '  Space          select / unselect game',
            '  Enter          show game details',
            '  A / N          select all visible / none',
            '  D              download selected (or all visible) games',
            '  U              update details of selected (or all visible) games',
            '  R              refresh library list (hidden status, tags)',
            '  /              search',
            '  T / H          change tag / hidden filter',
        ]);
    }

    // ---- rendering --------------------------------------------------------------------------------------------

    private function render(): void
    {
        [$width, $height] = $this->console->getSize();
        $size = [$width, $height];
        if ($size !== $this->previousSize) {
            $this->previousRows = [];
            $this->previousSize = $size;
            $this->console->write("\e[0m\e[2J");
        }

        $canvas = new Canvas($width, $height);
        if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT) {
            $canvas->centered(0, intdiv($height, 2), $width, sprintf('Please enlarge the window (at least %dx%d)', self::MIN_WIDTH, self::MIN_HEIGHT), Theme::WARN);
            $this->flush($canvas);

            return;
        }

        // title bar
        $canvas->centered(0, 0, $width, 'GOG Downloader · Library & Download Manager', Theme::TITLE_BAR);

        // menu
        $menuX = self::MENU_X;
        $menuY = self::MENU_Y;
        $labels = [...array_map(fn (Page $page) => $page->getMenuLabel(), $this->pages), 'Exit'];
        foreach ($labels as $index => $label) {
            $selected = $index === $this->selectedPage;
            $style = $selected ? ($this->menuFocused ? Theme::MENU_SELECTED : Theme::MENU_SELECTED_UNFOCUSED) : Theme::MENU;
            $canvas->textFixed($menuX, $menuY + $index * 2, ' ' . $label, $style, self::MENU_WIDTH);
        }

        // job summary below the menu
        if ($this->job !== null) {
            $summaryY = $menuY + count($labels) * 2 + 1;
            if ($summaryY < $height - 5) {
                $canvas->text($menuX, $summaryY, $this->job->isRunning() ? 'Running:' : 'Last job:', Theme::DIM);
                $canvas->text($menuX, $summaryY + 1, $this->job->title, Theme::INFO, self::MENU_WIDTH);
                $canvas->text($menuX, $summaryY + 2, $this->getJobShortStatus(), Theme::TEXT, self::MENU_WIDTH);
            }
        }

        // content
        $contentX = $menuX + self::MENU_WIDTH + 2;
        $contentWidth = $width - $contentX - 1;
        $contentY = 2;
        $contentHeight = $height - 4;
        $page = $this->pages[$this->selectedPage] ?? null;
        if ($page !== null) {
            $page->render($canvas, $contentX, $contentY, $contentWidth, $contentHeight, !$this->menuFocused && !count($this->modals));
        } else {
            $canvas->centered($contentX, $contentY + 2, $contentWidth, 'Exit GOG Downloader', Theme::HEADING);
            $canvas->centered($contentX, $contentY + 4, $contentWidth, 'Press Enter to exit.', Theme::TEXT);
        }

        // hints & status bar
        $hints = $this->menuFocused
            ? '↑↓ choose · Enter open · F1 help · q exit'
            : ($page?->getHints() ?? '');
        $canvas->text(1, $height - 2, $hints, Theme::DIM, $width - 2);

        $status = $this->loggedIn ? ('● Logged in' . ($this->username ? " as {$this->username}" : '')) : '○ Not logged in';
        $canvas->text(1, $height - 1, $status, $this->loggedIn ? Theme::STATUS_BAR_OK : Theme::STATUS_BAR);
        $right = 'v' . ltrim($this->version, 'v') . ($this->newVersion ? " (v{$this->newVersion} available)" : '') . '  ' . date('D j M Y H:i');
        $canvas->text($width - Canvas::width($right) - 1, $height - 1, $right, $this->newVersion ? Theme::STATUS_BAR_WARN : Theme::STATUS_BAR);
        if ($this->job?->isRunning()) {
            $jobStatus = $this->job->title . ' · ' . $this->getJobShortStatus();
            $space = $width - Canvas::width($right) - 30;
            if ($space > 10) {
                $jobStatus = Canvas::fit($jobStatus, min($space, Canvas::width($jobStatus)));
                $canvas->text(intdiv($width - Canvas::width($jobStatus), 2), $height - 1, $jobStatus, Theme::STATUS_BAR_WARN);
            }
        }

        if (count($this->modals)) {
            $canvas->setCursor(null, null);
            foreach ($this->modals as $modal) {
                $modal->render($canvas);
            }
        }

        $this->flush($canvas);
    }

    private function flush(Canvas $canvas): void
    {
        $output = '';
        foreach ($canvas->renderRows() as $index => $row) {
            if (($this->previousRows[$index] ?? null) !== $row) {
                $output .= "\e[" . ($index + 1) . ';1H' . $row;
                $this->previousRows[$index] = $row;
            }
        }
        $cursor = $canvas->getCursor();
        if ($cursor !== null) {
            $output .= "\e[" . ($cursor[1] + 1) . ';' . ($cursor[0] + 1) . "H\e[?25h";
        } else {
            $output .= "\e[?25l";
        }
        $this->console->write($output);
    }
}
