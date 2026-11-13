<?php

namespace App\Tui\Terminal;

/**
 * Platform specific raw terminal access.
 */
interface Console
{
    /**
     * Switches the terminal into raw mode (no echo, no line buffering).
     */
    public function enable(): void;

    /**
     * Restores the terminal to the state before enable() was called.
     */
    public function restore(): void;

    /**
     * @return array{int, int} width and height in cells
     */
    public function getSize(): array;

    /**
     * Waits up to $timeoutMs milliseconds for input and returns all pending keys.
     *
     * @return list<Key>
     */
    public function readKeys(int $timeoutMs): array;

    public function write(string $data): void;

    /**
     * Enables or disables mouse reporting. While enabled, the terminal can't be used to select text with the mouse.
     */
    public function setMouseEnabled(bool $enabled): void;
}
