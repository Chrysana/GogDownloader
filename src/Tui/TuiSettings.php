<?php

namespace App\Tui;

use App\Enum\Setting;
use App\Service\Persistence\PersistenceManager;

/**
 * Preferences of the interactive UI, stored as a JSON blob in the settings storage.
 */
final class TuiSettings
{
    /** @var list<string> */
    public array $platforms = ['windows', 'linux', 'mac'];

    public string $languages = '';

    public bool $englishFallback = true;

    public bool $installers = true;

    public bool $patches = true;

    public bool $extras = false;

    public bool $skipExistingExtras = true;

    public bool $removeInvalid = false;

    public string $bandwidth = '';

    /** hide | show | only */
    public string $hiddenFilter = 'hide';

    public bool $mouse = true;

    public static function load(PersistenceManager $persistence): self
    {
        $settings = new self();
        $raw = $persistence->getSetting(Setting::TuiOptions);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return $settings;
        }

        foreach (get_object_vars($settings) as $property => $default) {
            if (array_key_exists($property, $data) && gettype($data[$property]) === gettype($default)) {
                $settings->{$property} = $data[$property];
            }
        }

        return $settings;
    }

    public function save(PersistenceManager $persistence): void
    {
        $persistence->storeSetting(Setting::TuiOptions, json_encode(get_object_vars($this)));
    }
}
