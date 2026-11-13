<?php

namespace App\Tui\Page;

use App\Enum\Language;
use App\Enum\NamingConvention;
use App\Enum\Setting;
use App\Service\FolderMigrator;
use App\Tui\App;
use App\Tui\Canvas;
use App\Tui\Modal;
use App\Tui\Theme;
use App\Tui\Widget\Button;
use App\Tui\Widget\Checkbox;
use App\Tui\Widget\RadioGroup;
use App\Tui\Widget\TextInput;

final class SettingsPage extends Page
{
    private TextInput $directory;

    /** @var array<string, Checkbox> */
    private array $platforms = [];

    private TextInput $languages;

    private Checkbox $englishFallback;

    private Checkbox $installers;

    private Checkbox $patches;

    private Checkbox $extras;

    private Checkbox $skipExistingExtras;

    private Checkbox $removeInvalid;

    private Checkbox $mouse;

    private TextInput $bandwidth;

    private RadioGroup $naming;

    private Button $save;

    private Button $revert;

    private ?string $message = null;

    private bool $messageIsError = false;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->directory = $this->form->add(new TextInput(40, placeholder: 'current directory'));
        foreach (['windows' => 'Windows', 'linux' => 'Linux', 'mac' => 'macOS'] as $platform => $label) {
            $this->platforms[$platform] = $this->form->add(new Checkbox($label));
        }
        $this->languages = $this->form->add(new TextInput(20, placeholder: 'all languages'));
        $this->englishFallback = $this->form->add(new Checkbox('Fall back to English when the language is not available'));
        $this->installers = $this->form->add(new Checkbox('Installers'));
        $this->patches = $this->form->add(new Checkbox('Patches'));
        $this->extras = $this->form->add(new Checkbox('Extras (manuals, soundtracks, ...)'));
        $this->skipExistingExtras = $this->form->add(new Checkbox("Don't download extras again if the file already exists"));
        $this->removeInvalid = $this->form->add(new Checkbox('Delete and re-download files that fail the checksum check'));
        $this->bandwidth = $this->form->add(new TextInput(12, placeholder: 'unlimited'));
        $this->naming = $this->form->add(new RadioGroup([
            NamingConvention::Title->value => "Game title (Baldur's Gate II - Enhanced Edition)",
            NamingConvention::GogSlug->value => 'GOG slug (baldurs_gate_2_enhanced_edition)',
            NamingConvention::RomManager->value => 'ROM manager (win/baldurs_gate_2_enhanced_edition)',
        ], NamingConvention::GogSlug->value, vertical: true));
        // applied and stored immediately, it's an interface preference rather than a download setting
        $this->mouse = $this->form->add(new Checkbox(
            'Mouse support (turn off to select and copy text with the mouse)',
            $app->settings->mouse,
            function (bool $enabled) {
                $this->app->settings->mouse = $enabled;
                $this->app->settings->save($this->app->persistence);
                $this->app->setMouseEnabled($enabled);
            },
        ));
        $this->save = $this->form->add(new Button('Save', $this->saveSettings(...)));
        $this->revert = $this->form->add(new Button('Cancel', function () {
            $this->load();
            $this->message = 'Changes discarded.';
            $this->messageIsError = false;
        }));
        $this->load();
    }

    public function getMenuLabel(): string
    {
        return 'Settings';
    }

    public function onShow(): void
    {
        $this->message = null;
    }

    public function render(Canvas $canvas, int $x, int $y, int $width, int $height, bool $focused): void
    {
        $this->heading($canvas, $x, $y, $width, 'Download Settings');
        $row = $y + 2;
        $labelWidth = 18;
        $fieldX = $x + $labelWidth;

        $canvas->text($x, $row, 'Download folder:', Theme::LABEL);
        $this->directory->x = $fieldX;
        $this->directory->y = $row;
        $this->directory->width = max(20, $width - $labelWidth - 1);
        if (isset($_ENV['DOWNLOAD_DIRECTORY'])) {
            $canvas->text($fieldX, $row + 1, 'Overridden by the DOWNLOAD_DIRECTORY environment variable.', Theme::WARN, $width - $labelWidth);
        }
        $row += 2;

        $canvas->text($x, $row, 'Platforms:', Theme::LABEL);
        $platformX = $fieldX;
        foreach ($this->platforms as $checkbox) {
            $checkbox->x = $platformX;
            $checkbox->y = $row;
            $platformX += Canvas::width($checkbox->label) + 7;
        }
        $row += 2;

        $canvas->text($x, $row, 'Languages:', Theme::LABEL);
        $this->languages->x = $fieldX;
        $this->languages->y = $row;
        $canvas->text($fieldX + $this->languages->width + 2, $row, 'codes, comma separated (e.g. en,de)', Theme::DIM, $width - $labelWidth - $this->languages->width - 2);
        $this->englishFallback->x = $fieldX;
        $this->englishFallback->y = $row + 1;
        $row += 3;

        $canvas->text($x, $row, 'Download:', Theme::LABEL);
        $this->installers->x = $fieldX;
        $this->installers->y = $row;
        $this->patches->x = $fieldX + 18;
        $this->patches->y = $row;
        $this->extras->x = $fieldX;
        $this->extras->y = $row + 1;
        $this->skipExistingExtras->x = $fieldX;
        $this->skipExistingExtras->y = $row + 2;
        $this->skipExistingExtras->enabled = $this->extras->checked;
        $this->removeInvalid->x = $fieldX;
        $this->removeInvalid->y = $row + 3;
        $row += 5;

        $canvas->text($x, $row, 'Speed limit:', Theme::LABEL);
        $this->bandwidth->x = $fieldX;
        $this->bandwidth->y = $row;
        $canvas->text($fieldX + $this->bandwidth->width + 2, $row, 'bytes per second, k/m suffix allowed (e.g. 5m)', Theme::DIM, $width - $labelWidth - $this->bandwidth->width - 2);
        $row += 2;

        $canvas->text($x, $row, 'Folder names:', Theme::LABEL);
        $this->naming->x = $fieldX;
        $this->naming->y = $row;
        $row += $this->naming->getHeight() + 1;

        $canvas->text($x, $row, 'Interface:', Theme::LABEL);
        $this->mouse->x = $fieldX;
        $this->mouse->y = $row;
        $row += 2;

        $this->save->x = $x;
        $this->save->y = $row;
        $this->revert->x = $x + $this->save->getWidth() + 2;
        $this->revert->y = $row;
        if ($this->message !== null) {
            $canvas->text($this->revert->x + $this->revert->getWidth() + 3, $row, $this->message, $this->messageIsError ? Theme::BAD : Theme::GOOD, $width - 30);
        }
        $row += 2;

        $canvas->text($x, $row, 'Settings are stored in: ' . ($_ENV['CONFIG_DIRECTORY'] ?? getcwd()), Theme::DIM, $width);

        $this->form->render($canvas, $focused);
    }

    private function load(): void
    {
        $settings = $this->app->settings;
        $this->directory->setValue((string) ($this->app->persistence->getSetting(Setting::DownloadPath) ?? ''));
        foreach ($this->platforms as $platform => $checkbox) {
            $checkbox->checked = in_array($platform, $settings->platforms, true);
        }
        $this->languages->setValue($settings->languages);
        $this->englishFallback->checked = $settings->englishFallback;
        $this->installers->checked = $settings->installers;
        $this->patches->checked = $settings->patches;
        $this->extras->checked = $settings->extras;
        $this->skipExistingExtras->checked = $settings->skipExistingExtras;
        $this->removeInvalid->checked = $settings->removeInvalid;
        $this->bandwidth->setValue($settings->bandwidth);
        $naming = NamingConvention::tryFrom((string) $this->app->persistence->getSetting(Setting::NamingConvention)) ?? NamingConvention::GogSlug;
        $this->naming->value = $naming === NamingConvention::Custom ? NamingConvention::GogSlug->value : $naming->value;
    }

    private function saveSettings(): void
    {
        $platforms = array_keys(array_filter($this->platforms, fn (Checkbox $checkbox) => $checkbox->checked));
        if (!count($platforms)) {
            $this->error('Select at least one platform.');

            return;
        }

        $languages = array_values(array_filter(array_map('trim', explode(',', strtolower($this->languages->value)))));
        foreach ($languages as $language) {
            if (Language::tryFrom($language) === null) {
                $this->error("Unknown language code '{$language}'. Run 'gog-downloader languages' for the list.");

                return;
            }
        }

        $bandwidth = trim($this->bandwidth->value);
        if ($bandwidth !== '' && !preg_match('/^\d+\s*(b|k|kb|m|mb)?$/i', $bandwidth)) {
            $this->error('Invalid speed limit, use for example 500k or 5m.');

            return;
        }

        if (!$this->installers->checked && !$this->extras->checked) {
            $this->error('Select at least installers or extras.');

            return;
        }

        $directory = trim($this->directory->value);
        if ($directory !== '' && !preg_match('@^[0-9a-zA-Z.]+://@', $directory) && !is_dir($directory)) {
            if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
                $this->error("The folder '{$directory}' does not exist and could not be created.");

                return;
            }
        }

        $settings = $this->app->settings;
        $settings->platforms = $platforms;
        $settings->languages = implode(',', $languages);
        $settings->englishFallback = $this->englishFallback->checked;
        $settings->installers = $this->installers->checked;
        $settings->patches = $this->patches->checked;
        $settings->extras = $this->extras->checked;
        $settings->skipExistingExtras = $this->skipExistingExtras->checked;
        $settings->removeInvalid = $this->removeInvalid->checked;
        $settings->bandwidth = $bandwidth;
        $settings->save($this->app->persistence);

        $this->app->persistence->storeSetting(Setting::DownloadPath, $directory !== '' ? $directory : null);
        $this->languages->setValue($settings->languages);
        $this->message = 'Settings saved.';
        $this->messageIsError = false;
        $this->app->reloadData();

        $current = NamingConvention::tryFrom((string) $this->app->persistence->getSetting(Setting::NamingConvention)) ?? NamingConvention::GogSlug;
        $new = NamingConvention::from($this->naming->value);
        if ($current !== $new) {
            $this->changeNaming($current, $new);
        }
    }

    /**
     * Shows which existing folders would be renamed for the new naming scheme and lets the user decide.
     */
    private function changeNaming(NamingConvention $current, NamingConvention $new): void
    {
        $directory = $this->app->getDownloadDirectory();
        $plan = [];
        $this->app->busy('Checking existing folders...', function () use ($directory, $current, $new, &$plan) {
            $plan = $this->app->folderMigrator->plan($directory, $current, $new);
        });

        $moves = array_values(array_filter($plan, fn (array $entry) => $entry['status'] === FolderMigrator::MOVE));
        $conflicts = array_values(array_filter($plan, fn (array $entry) => $entry['status'] === FolderMigrator::CONFLICT));
        $store = function () use ($new) {
            $this->app->persistence->storeSetting(Setting::NamingConvention, $new->value);
            $this->app->log("Folder naming changed to '{$new->value}'.");
        };

        if (!count($moves) && !count($conflicts)) {
            $store();

            return;
        }

        $lines = [
            sprintf('Folder naming: %s', $new->getDescription()),
            '',
            sprintf('%d already downloaded game folder(s) can be renamed to match:', count($moves)),
        ];
        foreach ($moves as $entry) {
            $lines[] = '  ' . basename($entry['from']) . '  →  ' . basename($entry['to']);
        }
        if (count($conflicts)) {
            $lines[] = '';
            $lines[] = [sprintf('%d game(s) have both folders already and are left alone:', count($conflicts)), '1;93;44'];
            foreach ($conflicts as $entry) {
                $lines[] = '  ' . basename($entry['from']) . '  +  ' . basename($entry['to']);
            }
        }
        $lines[] = '';
        $lines[] = 'Without renaming, existing downloads would be downloaded again into the new folders.';

        $this->app->openModal(new Modal($this->app, 'Rename Folders', $lines, [
            'Rename Folders' => function () use ($plan, $store) {
                $result = [];
                $this->app->busy('Renaming folders...', function () use ($plan, &$result) {
                    $result = $this->app->folderMigrator->execute($plan);
                });
                $store();
                $moved = count(array_filter($result, fn (array $entry) => $entry['status'] === FolderMigrator::MOVED));
                $failed = array_values(array_filter($result, fn (array $entry) => $entry['status'] === FolderMigrator::FAILED));
                foreach ($failed as $entry) {
                    $this->app->log("Rename failed: {$entry['game']}: {$entry['message']}");
                }
                $this->app->log("Renamed {$moved} folder(s).");
                $this->message = count($failed)
                    ? sprintf('Renamed %d folder(s), %d failed (see Activity Log).', $moved, count($failed))
                    : sprintf('Settings saved, renamed %d folder(s).', $moved);
                $this->messageIsError = count($failed) > 0;
            },
            'Only Change Setting' => $store,
            'Cancel' => function () use ($current) {
                $this->naming->value = $current->value;
                $this->message = 'Folder naming unchanged.';
            },
        ], onEscape: function () use ($current) {
            $this->naming->value = $current->value;
            $this->message = 'Folder naming unchanged.';
        }));
    }

    private function error(string $message): void
    {
        $this->message = $message;
        $this->messageIsError = true;
    }
}
