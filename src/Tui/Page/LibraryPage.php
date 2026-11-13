<?php

namespace App\Tui\Page;

use App\DTO\GameDetail;
use App\DTO\GameExtra;
use App\DTO\GameInstaller;
use App\DTO\LibraryItem;
use App\Enum\Language;
use App\Tui\App;
use App\Tui\Canvas;
use App\Tui\Modal;
use App\Tui\Terminal\Key;
use App\Tui\Theme;
use App\Tui\Widget\Button;
use App\Tui\Widget\ListView;
use App\Tui\Widget\RadioGroup;
use App\Tui\Widget\Selector;
use App\Tui\Widget\TextInput;

final class LibraryPage extends Page
{
    private const ALL_TAGS = 'All tags';

    private const NO_TAGS = '(untagged)';

    private TextInput $search;

    private Selector $tag;

    private RadioGroup $hidden;

    private ListView $list;

    /** @var list<Button> */
    private array $buttons = [];

    /** @var list<int>|null */
    private ?array $rows = null;

    /** @var array<int, true> */
    private array $selected = [];

    public function __construct(App $app)
    {
        parent::__construct($app);

        $this->list = new ListView(
            count: fn () => count($this->getRows()),
            renderRow: $this->renderRow(...),
            onActivate: fn (int $index) => $this->showDetails($this->getRows()[$index] ?? null),
            onKey: $this->handleListKey(...),
            emptyText: 'No games to show. Refresh the library, or change the filters.',
            onClick: function (int $index, int $column) {
                // clicking the [ ] box toggles the selection
                $id = $this->getRows()[$index] ?? null;
                if ($id !== null && $column < 4) {
                    if (isset($this->selected[$id])) {
                        unset($this->selected[$id]);
                    } else {
                        $this->selected[$id] = true;
                    }
                }
            },
        );
        $this->search = $this->form->add(new TextInput(20, onChange: fn () => $this->invalidate(), placeholder: 'type to search'));
        $this->tag = $this->form->add(new Selector($this->getTagOptions(...), self::ALL_TAGS, 22, fn () => $this->invalidate()));
        $this->hidden = $this->form->add(new RadioGroup(
            ['hide' => 'Hide', 'show' => 'Show', 'only' => 'Only'],
            $app->settings->hiddenFilter,
            function (string $value) {
                $this->app->settings->hiddenFilter = $value;
                $this->app->settings->save($this->app->persistence);
                $this->invalidate();
            },
        ));
        $this->form->add($this->list);
        $this->buttons = [
            $this->form->add(new Button('Download', $this->download(...))),
            $this->form->add(new Button('Update Details', $this->update(...))),
            $this->form->add(new Button('Refresh Library', fn () => $this->app->refreshLibrary())),
            $this->form->add(new Button('Select All', $this->selectAll(...))),
            $this->form->add(new Button('Select None', fn () => $this->selected = [])),
        ];
    }

    public function getMenuLabel(): string
    {
        return 'Game Library';
    }

    public function getHints(): string
    {
        return 'Space select · Enter details · D download · U update · R refresh · / search · F1 help · Esc menu';
    }

    public function onShow(): void
    {
        $this->form->focus($this->list);
    }

    public function invalidate(): void
    {
        $this->rows = null;
    }

    public function handleKey(Key $key): bool
    {
        if ($this->form->getFocused() === $this->search && $key->is('esc', 'enter')) {
            $this->form->focus($this->list);

            return true;
        }

        if (!$this->capturesText() && $key->name === 'char') {
            switch (mb_strtolower($key->char)) {
                case '/':
                    $this->form->focus($this->search);

                    return true;
                case 'd':
                    $this->download();

                    return true;
                case 'u':
                    $this->update();

                    return true;
                case 'r':
                    $this->app->refreshLibrary();

                    return true;
                case 'a':
                    $this->selectAll();

                    return true;
                case 'n':
                    $this->selected = [];

                    return true;
                case 't':
                    $this->tag->handleKey(new Key('right'));

                    return true;
                case 'h':
                    $this->hidden->handleKey(new Key('right'));

                    return true;
            }
        }

        return parent::handleKey($key);
    }

    public function render(Canvas $canvas, int $x, int $y, int $width, int $height, bool $focused): void
    {
        $library = $this->app->getLibrary();
        $details = $this->app->getDetails();
        $rows = $this->getRows();

        $this->heading($canvas, $x, $y, $width, 'Game Library');

        // filters
        $filterY = $y + 2;
        $canvas->text($x, $filterY, 'Search:', Theme::LABEL);
        $this->search->x = $x + 8;
        $this->search->y = $filterY;
        $this->search->width = max(12, min(34, $width - 45));
        $tagX = $this->search->x + $this->search->width + 3;
        $canvas->text($tagX, $filterY, 'Tag:', Theme::LABEL);
        $this->tag->x = $tagX + 5;
        $this->tag->y = $filterY;
        $canvas->text($x, $filterY + 1, 'Hidden:', Theme::LABEL);
        $this->hidden->x = $x + 8;
        $this->hidden->y = $filterY + 1;
        $canvas->text($x + 50, $filterY + 1, 'Press F1 for keyboard shortcuts', Theme::DIM, max(0, $width - 50));

        // list header
        $headerY = $filterY + 3;
        [$titleWidth, $sizeWidth, $tagsWidth] = $this->getColumns($width);
        $canvas->text($x, $headerY, Canvas::fit('Sel', 5) . Canvas::fit('Title', $titleWidth) . Canvas::fit('Size', $sizeWidth, 'right') . '  ' . Canvas::fit('Tags', $tagsWidth), Theme::LIST_HEADER, $width);

        $this->list->x = $x;
        $this->list->y = $headerY + 1;
        $this->list->width = $width - 1;
        $this->list->height = max(3, $height - 10);

        // summary line
        $summaryY = $this->list->y + $this->list->height + 1;
        $hiddenCount = count(array_filter($library, fn (LibraryItem $item) => $item->hidden));
        $summary = sprintf(
            'Showing %d of %d games · %d hidden · %d selected · %d with file info',
            count($rows),
            max(count($library), count($details)),
            $hiddenCount,
            count($this->selected),
            count($details),
        );
        $canvas->text($x, $summaryY, $summary, Theme::DIM, $width);

        // buttons
        $buttonX = $x;
        foreach ($this->buttons as $button) {
            $button->x = $buttonX;
            $button->y = $summaryY + 1;
            $buttonX += $button->getWidth() + 2;
        }

        $this->form->render($canvas, $focused);
    }

    // ---- sizes ------------------------------------------------------------------------------------------------

    /**
     * Size of the files that would be downloaded with the current settings.
     */
    public function getDownloadSize(GameDetail $detail): int
    {
        $settings = $this->app->settings;
        $languages = $this->getLanguageFilter();
        $size = 0;
        if ($settings->installers) {
            $matching = array_filter($detail->downloads, function (GameInstaller $installer) use ($settings, $languages) {
                if (!in_array($installer->platform, $settings->platforms, true)) {
                    return false;
                }
                if (!$settings->patches && $installer->isPatch) {
                    return false;
                }

                return $languages === null || in_array($installer->language, $languages, true);
            });
            if (!count($matching) && $languages !== null && $settings->englishFallback) {
                $matching = array_filter(
                    $detail->downloads,
                    fn (GameInstaller $installer) => in_array($installer->platform, $settings->platforms, true)
                        && in_array($installer->language, [Language::English->value, Language::English->getLocalName()], true),
                );
            }
            foreach ($matching as $installer) {
                $size += (int) $installer->size;
            }
        }
        if ($settings->extras) {
            foreach ($detail->extras as $extra) {
                $size += (int) $extra->size;
            }
        }

        return $size;
    }

    // ---- rows -------------------------------------------------------------------------------------------------

    /**
     * @return list<int> game ids matching the filters, sorted by title
     */
    private function getRows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $library = $this->app->getLibrary();
        $details = $this->app->getDetails();
        $titles = [];
        foreach ($details as $id => $detail) {
            $titles[$id] = $detail->title;
        }
        foreach ($library as $id => $item) {
            $titles[$id] = $item->title;
        }

        $search = mb_strtolower(trim($this->search->value));
        $tag = $this->tag->value;
        $hiddenFilter = $this->hidden->value;

        $rows = [];
        foreach ($titles as $id => $title) {
            $item = $library[$id] ?? null;
            $isHidden = $item?->hidden ?? false;
            if ($hiddenFilter === 'hide' && $isHidden) {
                continue;
            }
            if ($hiddenFilter === 'only' && !$isHidden) {
                continue;
            }
            if ($tag === self::NO_TAGS && $item !== null && count($item->tags)) {
                continue;
            }
            if ($tag !== self::ALL_TAGS && $tag !== self::NO_TAGS && !$item?->hasTag($tag)) {
                continue;
            }
            if ($search !== '' && !str_contains(mb_strtolower($title), $search) && !str_contains((string) $id, $search)) {
                continue;
            }
            $rows[$id] = $title;
        }
        natcasesort($rows);

        return $this->rows = array_keys($rows);
    }

    /**
     * @return list<string>
     */
    private function getTagOptions(): array
    {
        return [self::ALL_TAGS, ...$this->app->ownedItemsManager->getTagNames(), self::NO_TAGS];
    }

    /**
     * @return array{int, int, int} title, size and tags column widths
     */
    private function getColumns(int $width): array
    {
        $sizeWidth = 10;
        $tagsWidth = max(10, min(30, intdiv($width, 4)));
        $titleWidth = max(10, $width - 5 - $sizeWidth - 2 - $tagsWidth - 2);

        return [$titleWidth, $sizeWidth, $tagsWidth];
    }

    private function renderRow(Canvas $canvas, int $index, int $x, int $y, int $width, bool $isCursor, bool $focused): void
    {
        $id = $this->getRows()[$index] ?? null;
        if ($id === null) {
            return;
        }
        $item = $this->app->getLibrary()[$id] ?? null;
        $detail = $this->app->getDetails()[$id] ?? null;
        [$titleWidth, $sizeWidth, $tagsWidth] = $this->getColumns($width + 1);

        $base = $isCursor ? ($focused ? Theme::LIST_CURSOR : Theme::LIST_CURSOR_UNFOCUSED) : Theme::TEXT;
        $dim = $isCursor ? $base : Theme::DIM;

        $canvas->text($x, $y, isset($this->selected[$id]) ? '[X]' : '[ ]', $isCursor ? $base : Theme::VALUE);
        $title = $item?->title ?? $detail?->title ?? (string) $id;
        if ($item?->hidden) {
            $title .= ' (hidden)';
        }
        $canvas->textFixed($x + 5, $y, $title, $item?->hidden ? $dim : $base, $titleWidth);

        $size = $detail !== null ? App::formatBytes($this->getDownloadSize($detail)) : 'no info';
        $canvas->textFixed($x + 5 + $titleWidth, $y, $size, $detail !== null ? $base : $dim, $sizeWidth, 'right');
        $canvas->textFixed($x + 5 + $titleWidth + $sizeWidth + 2, $y, implode(', ', $item?->tags ?? []), $isCursor ? $base : Theme::INFO, $tagsWidth);
    }

    private function handleListKey(Key $key, int $cursor): bool
    {
        if ($key->isChar(' ')) {
            $id = $this->getRows()[$cursor] ?? null;
            if ($id !== null) {
                if (isset($this->selected[$id])) {
                    unset($this->selected[$id]);
                } else {
                    $this->selected[$id] = true;
                }
                $this->list->handleKey(new Key('down'));
            }

            return true;
        }

        return false;
    }

    private function selectAll(): void
    {
        foreach ($this->getRows() as $id) {
            $this->selected[$id] = true;
        }
    }

    /**
     * @return list<int> selected games, or all visible ones when nothing is selected
     */
    private function getTargetIds(): array
    {
        if (count($this->selected)) {
            return array_keys($this->selected);
        }

        return $this->getRows();
    }

    /**
     * @return list<string>|null language codes and local names, or null for all languages
     */
    private function getLanguageFilter(): ?array
    {
        $codes = array_filter(array_map('trim', explode(',', $this->app->settings->languages)));
        if (!count($codes)) {
            return null;
        }
        $result = [];
        foreach ($codes as $code) {
            $language = Language::tryFrom($code);
            $result[] = $code;
            if ($language) {
                $result[] = $language->getLocalName();
            }
        }

        return $result;
    }

    // ---- actions ----------------------------------------------------------------------------------------------

    private function download(): void
    {
        $ids = $this->getTargetIds();
        if (!count($ids)) {
            $this->app->showMessage('Download', 'There are no games to download, check your filters.');

            return;
        }

        $details = $this->app->getDetails();
        $withInfo = array_values(array_filter($ids, fn (int $id) => isset($details[$id])));
        $missing = count($ids) - count($withInfo);
        if (!count($withInfo)) {
            $this->app->confirm('Download', [
                'None of these games have file information yet.',
                '',
                'Update their details first? (You can download them once the update finishes.)',
            ], fn () => $this->startUpdate($ids), 'Update Details', 'Cancel');

            return;
        }

        $size = array_sum(array_map(fn (int $id) => $this->getDownloadSize($details[$id]), $withInfo));
        $directory = $this->app->getDownloadDirectory();
        $lines = [
            sprintf('Download %d game%s (up to %s)?', count($withInfo), count($withInfo) === 1 ? '' : 's', App::formatBytes($size)),
            '',
            ['Destination: ' . $directory, Theme::MODAL_TEXT],
            '',
            'Files that are already downloaded and valid are skipped.',
            'Interrupted downloads are resumed.',
        ];
        if ($missing > 0) {
            $lines[] = '';
            $lines[] = ["{$missing} game(s) without file information will be skipped (use Update Details).", '1;93;44'];
        }

        $lines[] = '';
        $lines[] = ['Test Run: create the folders and check what would be downloaded, without downloading.', Theme::MODAL_TEXT];

        $start = function (bool $testRun) use ($withInfo) {
            $what = count($withInfo) === 1
                ? ($this->app->getDetails()[$withInfo[0]]->title ?? '1 game')
                : count($withInfo) . ' games';
            $arguments = $this->app->getDownloadArguments();
            if ($testRun) {
                $arguments[] = '--test-run';
            }
            if ($this->app->startJob(($testRun ? 'Test run: ' : 'Download ') . $what, 'download', $arguments, $withInfo)) {
                if (!$testRun) {
                    $this->selected = [];
                }
                $this->app->selectPage(DownloadsPage::class, focusContent: true);
            }
        };

        $this->app->openModal(new Modal($this->app, 'Download', $lines, [
            'Download' => fn () => $start(false),
            'Test Run' => fn () => $start(true),
            'Cancel' => null,
        ]));
    }

    private function update(): void
    {
        $ids = $this->getTargetIds();
        if (!count($ids)) {
            $this->app->showMessage('Update', 'There are no games to update, check your filters.');

            return;
        }
        $this->app->confirm('Update Details', [
            sprintf('Fetch file information for %d game%s from GOG?', count($ids), count($ids) === 1 ? '' : 's'),
            '',
            'This takes a few seconds per game.',
        ], fn () => $this->startUpdate($ids), 'Update', 'Cancel');
    }

    /**
     * @param list<int> $ids
     */
    private function startUpdate(array $ids): void
    {
        if ($this->app->startJob(
            sprintf('Update %d game%s', count($ids), count($ids) === 1 ? '' : 's'),
            'update',
            ['update-database', '--json-progress', '--skip-errors', '--include-hidden'],
            $ids,
        )) {
            $this->app->selectPage(UpdatePage::class, focusContent: true);
        }
    }

    private function showDetails(?int $id): void
    {
        if ($id === null) {
            return;
        }
        $item = $this->app->getLibrary()[$id] ?? null;
        $detail = $this->app->getDetails()[$id] ?? null;

        $lines = [
            ['ID: ' . $id . '   Slug: ' . ($item?->slug ?: $detail?->slug ?: '-'), Theme::MODAL_TEXT],
            'Hidden: ' . ($item?->hidden ? 'yes' : 'no') . '   Tags: ' . (count($item?->tags ?? []) ? implode(', ', $item->tags) : '-'),
            '',
        ];
        if ($detail === null) {
            $lines[] = ['No file information yet, use "Update Details".', '1;93;44'];
        } else {
            $lines[] = ['Installers & patches:', Theme::MODAL_BORDER];
            foreach ($detail->downloads as $download) {
                assert($download instanceof GameInstaller);
                $lines[] = sprintf(
                    '  %-8s %-6s %9s  %s%s',
                    $download->platform,
                    $download->language,
                    App::formatBytes((int) $download->size),
                    $download->name,
                    $download->isPatch ? ' (patch)' : '',
                );
            }
            if (count($detail->extras)) {
                $lines[] = '';
                $lines[] = ['Extras:', Theme::MODAL_BORDER];
                foreach ($detail->extras as $extra) {
                    assert($extra instanceof GameExtra);
                    $lines[] = sprintf('  %9s  %s', App::formatBytes((int) $extra->size), $extra->name);
                }
            }
            $lines[] = '';
            $lines[] = 'Size with current settings: ' . App::formatBytes($this->getDownloadSize($detail));
        }

        $title = $item?->title ?? $detail?->title ?? (string) $id;
        $this->app->openModal(new Modal($this->app, $title, $lines, [
            'Download' => function () use ($id) {
                $this->selected = [$id => true];
                $this->download();
            },
            'Update Details' => fn () => $this->startUpdate([$id]),
            'Close' => null,
        ], preferredWidth: 70));
    }
}
