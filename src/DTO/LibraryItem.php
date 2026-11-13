<?php

namespace App\DTO;

/**
 * A lightweight entry describing an owned product as seen in the GOG library listing.
 * Unlike GameDetail this does not contain any download information, but it is cheap to
 * fetch for the whole library and carries library-only metadata like hidden status and tags.
 */
final readonly class LibraryItem
{
    /**
     * @param list<string> $tags tag names (as defined by the user in their GOG library)
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $slug,
        public bool $hidden,
        public array $tags,
    ) {
    }

    public function hasTag(string $tag): bool
    {
        $tag = mb_strtolower($tag);
        foreach ($this->tags as $existing) {
            if (mb_strtolower($existing) === $tag) {
                return true;
            }
        }

        return false;
    }
}
