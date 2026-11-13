<?php

namespace App\Tui;

/**
 * A grid of character cells with styles. Drawing operations clip to the canvas bounds.
 * Styles are SGR parameter strings, e.g. "97;44" for bright white on blue.
 */
final class Canvas
{
    /** @var list<list<array{string, string}>> */
    private array $cells = [];

    private ?array $cursor = null;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
        private readonly string $defaultStyle = Theme::BASE,
    ) {
        $row = array_fill(0, max(0, $width), [' ', $defaultStyle]);
        $this->cells = array_fill(0, max(0, $height), $row);
    }

    public function fill(int $x, int $y, int $width, int $height, string $style, string $char = ' '): void
    {
        for ($row = $y; $row < $y + $height; ++$row) {
            if ($row < 0 || $row >= $this->height) {
                continue;
            }
            for ($column = $x; $column < $x + $width; ++$column) {
                if ($column >= 0 && $column < $this->width) {
                    $this->cells[$row][$column] = [$char, $style];
                }
            }
        }
    }

    /**
     * Writes text at the given position, truncated to $maxWidth display columns (if given).
     * Returns the number of columns written.
     */
    public function text(int $x, int $y, string $text, string $style, ?int $maxWidth = null): int
    {
        if ($y < 0 || $y >= $this->height) {
            return 0;
        }

        $written = 0;
        $column = $x;
        foreach (self::characters($text) as $char) {
            $charWidth = self::charWidth($char);
            if ($charWidth === 0) {
                continue;
            }
            if ($maxWidth !== null && $written + $charWidth > $maxWidth) {
                break;
            }
            if ($column >= 0 && $column + $charWidth - 1 < $this->width) {
                $this->cells[$y][$column] = [$char, $style];
                if ($charWidth === 2) {
                    $this->cells[$y][$column + 1] = ['', $style]; // continuation of a wide character
                }
            }
            $column += $charWidth;
            $written += $charWidth;
        }

        return $written;
    }

    /**
     * Writes text padded (or truncated with an ellipsis) to exactly $width columns.
     */
    public function textFixed(int $x, int $y, string $text, string $style, int $width, string $align = 'left'): void
    {
        $text = self::fit($text, $width, $align);
        $this->text($x, $y, $text, $style, $width);
    }

    public function centered(int $x, int $y, int $width, string $text, string $style): void
    {
        $this->textFixed($x, $y, $text, $style, $width, 'center');
    }

    public function box(int $x, int $y, int $width, int $height, string $style, ?string $title = null): void
    {
        if ($width < 2 || $height < 2) {
            return;
        }
        $this->text($x, $y, '┌' . str_repeat('─', $width - 2) . '┐', $style);
        for ($row = $y + 1; $row < $y + $height - 1; ++$row) {
            $this->text($x, $row, '│', $style);
            $this->text($x + $width - 1, $row, '│', $style);
        }
        $this->text($x, $y + $height - 1, '└' . str_repeat('─', $width - 2) . '┘', $style);
        if ($title !== null && $width > 6) {
            $this->text($x + 2, $y, " {$title} ", $style, $width - 4);
        }
    }

    public function setCursor(?int $x, ?int $y): void
    {
        $this->cursor = $x === null || $y === null ? null : [$x, $y];
    }

    public function getCursor(): ?array
    {
        return $this->cursor;
    }

    /**
     * @return list<string> each row rendered to a string with escape sequences
     */
    public function renderRows(): array
    {
        $rows = [];
        foreach ($this->cells as $row) {
            $line = '';
            $currentStyle = null;
            foreach ($row as [$char, $style]) {
                if ($char === '') {
                    continue;
                }
                if ($style !== $currentStyle) {
                    $line .= "\e[0;{$style}m";
                    $currentStyle = $style;
                }
                $line .= $char;
            }
            $rows[] = $line . "\e[0m";
        }

        return $rows;
    }

    public static function width(string $text): int
    {
        $width = 0;
        foreach (self::characters($text) as $char) {
            $width += self::charWidth($char);
        }

        return $width;
    }

    public static function fit(string $text, int $width, string $align = 'left'): string
    {
        if ($width <= 0) {
            return '';
        }
        $textWidth = self::width($text);
        if ($textWidth > $width) {
            $result = '';
            $used = 0;
            foreach (self::characters($text) as $char) {
                $charWidth = self::charWidth($char);
                if ($used + $charWidth > $width - 1) {
                    break;
                }
                $result .= $char;
                $used += $charWidth;
            }

            return $result . '…' . str_repeat(' ', max(0, $width - 1 - $used));
        }

        $padding = $width - $textWidth;

        return match ($align) {
            'right' => str_repeat(' ', $padding) . $text,
            'center' => str_repeat(' ', intdiv($padding, 2)) . $text . str_repeat(' ', $padding - intdiv($padding, 2)),
            default => $text . str_repeat(' ', $padding),
        };
    }

    /**
     * @return list<string>
     */
    private static function characters(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';

        return mb_str_split($text, 1, 'UTF-8');
    }

    private static function charWidth(string $char): int
    {
        // combining marks
        if (preg_match('/^\p{Mn}$/u', $char)) {
            return 0;
        }

        return mb_strwidth($char, 'UTF-8') >= 2 ? 2 : 1;
    }
}
