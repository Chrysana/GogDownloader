<?php

namespace App\Enum;

enum NamingConvention: string
{
    case Custom = 'custom';
    case GogSlug = 'gog-slug';
    case RomManager = 'rom-manager';
    case Title = 'title';

    public function getDescription(): string
    {
        return match ($this) {
            self::Custom => 'legacy custom names (deprecated)',
            self::GogSlug => 'GOG slug, e.g. baldurs_gate_2_enhanced_edition',
            self::RomManager => 'platform folder + GOG slug, e.g. win/baldurs_gate_2_enhanced_edition',
            self::Title => "game title, e.g. Baldur's Gate II - Enhanced Edition",
        };
    }
}
