<?php

namespace App\Tui\Terminal;

use FFI;
use FFI\CData;
use RuntimeException;

/**
 * Raw console access on Windows using the Win32 console API through FFI.
 */
final class WindowsConsole implements Console
{
    private const STD_INPUT_HANDLE = 4294967286; // (DWORD) -10

    private const STD_OUTPUT_HANDLE = 4294967285; // (DWORD) -11

    private const ENABLE_WINDOW_INPUT = 0x0008;

    private const ENABLE_MOUSE_INPUT = 0x0010;

    private const ENABLE_QUICK_EDIT_MODE = 0x0040;

    private const ENABLE_EXTENDED_FLAGS = 0x0080;

    private const ENABLE_PROCESSED_OUTPUT = 0x0001;

    private const ENABLE_WRAP_AT_EOL_OUTPUT = 0x0002;

    private const ENABLE_VIRTUAL_TERMINAL_PROCESSING = 0x0004;

    private const KEY_EVENT = 0x0001;

    private const MOUSE_EVENT = 0x0002;

    private const MOUSE_MOVED = 0x0001;

    private const MOUSE_WHEELED = 0x0004;

    private const MOUSE_HWHEELED = 0x0008;

    private const FROM_LEFT_1ST_BUTTON_PRESSED = 0x0001;

    private const RIGHTMOST_BUTTON_PRESSED = 0x0002;

    private const RIGHT_ALT_PRESSED = 0x0001;

    private const LEFT_ALT_PRESSED = 0x0002;

    private const RIGHT_CTRL_PRESSED = 0x0004;

    private const LEFT_CTRL_PRESSED = 0x0008;

    private const SHIFT_PRESSED = 0x0010;

    private const VIRTUAL_KEYS = [
        0x08 => 'backspace',
        0x09 => 'tab',
        0x0D => 'enter',
        0x1B => 'esc',
        0x21 => 'pageup',
        0x22 => 'pagedown',
        0x23 => 'end',
        0x24 => 'home',
        0x25 => 'left',
        0x26 => 'up',
        0x27 => 'right',
        0x28 => 'down',
        0x2D => 'insert',
        0x2E => 'delete',
        0x70 => 'f1', 0x71 => 'f2', 0x72 => 'f3', 0x73 => 'f4', 0x74 => 'f5', 0x75 => 'f6',
        0x76 => 'f7', 0x77 => 'f8', 0x78 => 'f9', 0x79 => 'f10', 0x7A => 'f11', 0x7B => 'f12',
    ];

    private FFI $ffi;

    private CData $input;

    private CData $output;

    private ?int $originalInputMode = null;

    private ?int $originalOutputMode = null;

    private ?int $originalOutputCodePage = null;

    private ?int $originalInputCodePage = null;

    /** @var resource */
    private $stdout;

    private ?int $pendingHighSurrogate = null;

    private bool $mouseEnabled = false;

    private int $previousButtons = 0;

    /** the console modes this class set, re-applied when another process changes them */
    private ?int $desiredInputMode = null;

    private ?int $desiredOutputMode = null;

    public function __construct()
    {
        if (!extension_loaded('ffi')) {
            throw new RuntimeException('The interactive UI on Windows requires the PHP FFI extension.');
        }

        $this->ffi = FFI::cdef(<<<'C'
            typedef unsigned short WORD;
            typedef unsigned long DWORD;
            typedef int BOOL;
            typedef short SHORT;
            typedef void* HANDLE;
            typedef unsigned int UINT;

            typedef struct { SHORT X; SHORT Y; } COORD;
            typedef struct { SHORT Left; SHORT Top; SHORT Right; SHORT Bottom; } SMALL_RECT;
            typedef struct {
                COORD dwSize;
                COORD dwCursorPosition;
                WORD wAttributes;
                SMALL_RECT srWindow;
                COORD dwMaximumWindowSize;
            } CONSOLE_SCREEN_BUFFER_INFO;

            typedef struct {
                BOOL bKeyDown;
                WORD wRepeatCount;
                WORD wVirtualKeyCode;
                WORD wVirtualScanCode;
                WORD UnicodeChar;
                DWORD dwControlKeyState;
            } KEY_EVENT_RECORD;

            typedef struct {
                COORD dwMousePosition;
                DWORD dwButtonState;
                DWORD dwControlKeyState;
                DWORD dwEventFlags;
            } MOUSE_EVENT_RECORD;

            typedef struct {
                WORD EventType;
                union {
                    KEY_EVENT_RECORD KeyEvent;
                    MOUSE_EVENT_RECORD MouseEvent;
                    unsigned char padding[16];
                } Event;
            } INPUT_RECORD;

            HANDLE GetStdHandle(DWORD nStdHandle);
            BOOL GetConsoleMode(HANDLE hConsoleHandle, DWORD* lpMode);
            BOOL SetConsoleMode(HANDLE hConsoleHandle, DWORD dwMode);
            BOOL GetNumberOfConsoleInputEvents(HANDLE hConsoleInput, DWORD* lpcNumberOfEvents);
            BOOL ReadConsoleInputW(HANDLE hConsoleInput, INPUT_RECORD* lpBuffer, DWORD nLength, DWORD* lpNumberOfEventsRead);
            BOOL GetConsoleScreenBufferInfo(HANDLE hConsoleOutput, CONSOLE_SCREEN_BUFFER_INFO* lpConsoleScreenBufferInfo);
            DWORD WaitForSingleObject(HANDLE hHandle, DWORD dwMilliseconds);
            UINT GetConsoleOutputCP(void);
            UINT GetConsoleCP(void);
            BOOL SetConsoleOutputCP(UINT wCodePageID);
            BOOL SetConsoleCP(UINT wCodePageID);
            C, 'kernel32.dll');

        $this->input = $this->ffi->GetStdHandle(self::STD_INPUT_HANDLE);
        $this->output = $this->ffi->GetStdHandle(self::STD_OUTPUT_HANDLE);
        $this->stdout = fopen('php://stdout', 'wb');
    }

    public static function isSupported(): bool
    {
        return PHP_OS_FAMILY === 'Windows' && extension_loaded('ffi');
    }

    public function enable(): void
    {
        $mode = $this->ffi->new('DWORD');
        if (!$this->ffi->GetConsoleMode($this->input, FFI::addr($mode))) {
            throw new RuntimeException('The interactive UI must be run in a console window (input is not a console).');
        }
        $this->originalInputMode = $mode->cdata;
        $this->applyInputMode();

        if ($this->ffi->GetConsoleMode($this->output, FFI::addr($mode))) {
            $this->originalOutputMode = $mode->cdata;
            $this->desiredOutputMode = self::ENABLE_PROCESSED_OUTPUT | self::ENABLE_VIRTUAL_TERMINAL_PROCESSING | ($this->originalOutputMode & self::ENABLE_WRAP_AT_EOL_OUTPUT);
            $this->ffi->SetConsoleMode($this->output, $this->desiredOutputMode);
        }

        $this->originalOutputCodePage = $this->ffi->GetConsoleOutputCP();
        $this->originalInputCodePage = $this->ffi->GetConsoleCP();
        $this->ffi->SetConsoleOutputCP(65001);
        $this->ffi->SetConsoleCP(65001);
    }

    public function setMouseEnabled(bool $enabled): void
    {
        $this->mouseEnabled = $enabled;
        $this->previousButtons = 0;
        if ($this->originalInputMode !== null) {
            $this->applyInputMode();
        }
    }

    public function restore(): void
    {
        $this->desiredInputMode = null;
        $this->desiredOutputMode = null;
        if ($this->originalInputMode !== null) {
            $this->ffi->SetConsoleMode($this->input, $this->originalInputMode | self::ENABLE_EXTENDED_FLAGS);
            $this->originalInputMode = null;
        }
        if ($this->originalOutputMode !== null) {
            $this->ffi->SetConsoleMode($this->output, $this->originalOutputMode);
            $this->originalOutputMode = null;
        }
        if ($this->originalOutputCodePage !== null) {
            $this->ffi->SetConsoleOutputCP($this->originalOutputCodePage);
            $this->originalOutputCodePage = null;
        }
        if ($this->originalInputCodePage !== null) {
            $this->ffi->SetConsoleCP($this->originalInputCodePage);
            $this->originalInputCodePage = null;
        }
    }

    public function getSize(): array
    {
        $info = $this->getBufferInfo();
        if ($info === null) {
            return [80, 25];
        }

        return [
            max(1, $info->srWindow->Right - $info->srWindow->Left + 1),
            max(1, $info->srWindow->Bottom - $info->srWindow->Top + 1),
        ];
    }

    public function readKeys(int $timeoutMs): array
    {
        $this->ensureModes();

        // WAIT_OBJECT_0 = 0, anything else means timeout or failure
        if ($this->ffi->WaitForSingleObject($this->input, max(0, $timeoutMs)) !== 0) {
            return [];
        }

        $count = $this->ffi->new('DWORD');
        if (!$this->ffi->GetNumberOfConsoleInputEvents($this->input, FFI::addr($count)) || $count->cdata === 0) {
            return [];
        }

        $size = min(256, $count->cdata);
        $records = $this->ffi->new("INPUT_RECORD[{$size}]");
        $read = $this->ffi->new('DWORD');
        if (!$this->ffi->ReadConsoleInputW($this->input, $records, $size, FFI::addr($read))) {
            return [];
        }

        $keys = [];
        for ($i = 0; $i < $read->cdata; ++$i) {
            $record = $records[$i];
            if ($record->EventType === self::MOUSE_EVENT) {
                if ($this->mouseEnabled && ($key = $this->translateMouse($record->Event->MouseEvent)) !== null) {
                    $keys[] = $key;
                }

                continue;
            }
            if ($record->EventType !== self::KEY_EVENT) {
                continue;
            }
            $event = $record->Event->KeyEvent;
            if (!$event->bKeyDown) {
                continue;
            }
            $key = $this->translate($event->wVirtualKeyCode, $event->UnicodeChar, $event->dwControlKeyState);
            if ($key === null) {
                continue;
            }
            for ($repeat = 0; $repeat < max(1, $event->wRepeatCount); ++$repeat) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function write(string $data): void
    {
        fwrite($this->stdout, $data);
        fflush($this->stdout);
    }

    private function applyInputMode(): void
    {
        // no line input, no echo, no ctrl+c processing (handled by the app);
        // quick edit (selecting text with the mouse) has to be off for mouse events to be reported
        $mode = self::ENABLE_WINDOW_INPUT | self::ENABLE_EXTENDED_FLAGS;
        if ($this->mouseEnabled) {
            $mode |= self::ENABLE_MOUSE_INPUT;
        } else {
            $mode |= ($this->originalInputMode ?? 0) & self::ENABLE_QUICK_EDIT_MODE;
        }
        $this->desiredInputMode = $mode;
        $this->ffi->SetConsoleMode($this->input, $mode);
    }

    /**
     * Other programs attached to the same console (e.g. cmd.exe, which is used to start and stop the background
     * jobs) reset the console modes, which turns quick edit back on and disables mouse input. Restore our modes
     * whenever they were changed.
     */
    private function ensureModes(): void
    {
        if ($this->desiredInputMode === null) {
            return;
        }

        $mode = $this->ffi->new('DWORD');
        if ($this->ffi->GetConsoleMode($this->input, FFI::addr($mode))) {
            $current = (int) $mode->cdata;
            // the extended flags bit is only meaningful when setting the mode
            if (($current | self::ENABLE_EXTENDED_FLAGS) !== ($this->desiredInputMode | self::ENABLE_EXTENDED_FLAGS)) {
                $this->ffi->SetConsoleMode($this->input, $this->desiredInputMode);
                $this->previousButtons = 0;
            }
        }
        if ($this->desiredOutputMode !== null && $this->ffi->GetConsoleMode($this->output, FFI::addr($mode))
            && (int) $mode->cdata !== $this->desiredOutputMode
        ) {
            $this->ffi->SetConsoleMode($this->output, $this->desiredOutputMode);
        }
        if ((int) $this->ffi->GetConsoleOutputCP() !== 65001) {
            $this->ffi->SetConsoleOutputCP(65001);
            $this->ffi->SetConsoleCP(65001);
        }
    }

    private function getBufferInfo(): ?CData
    {
        $info = $this->ffi->new('CONSOLE_SCREEN_BUFFER_INFO');
        if (!$this->ffi->GetConsoleScreenBufferInfo($this->output, FFI::addr($info))) {
            return null;
        }

        return $info;
    }

    private function translateMouse(CData $event): ?Key
    {
        // positions are relative to the whole screen buffer, convert them to the visible window
        // note: FFI fields must be read directly - inside "??" FFI returns CData objects instead of integers
        $info = $this->getBufferInfo();
        $left = $info === null ? 0 : (int) $info->srWindow->Left;
        $top = $info === null ? 0 : (int) $info->srWindow->Top;
        $x = (int) $event->dwMousePosition->X - $left;
        $y = (int) $event->dwMousePosition->Y - $top;
        $flags = (int) $event->dwEventFlags;
        $buttons = (int) $event->dwButtonState;

        if ($flags & self::MOUSE_WHEELED) {
            // the high word is the signed wheel delta, positive means away from the user (up)
            $delta = ($buttons >> 16) & 0xFFFF;
            if ($delta >= 0x8000) {
                $delta -= 0x10000;
            }

            return Key::mouse($delta > 0 ? 'scrollup' : 'scrolldown', $x, $y);
        }
        if ($flags & (self::MOUSE_HWHEELED | self::MOUSE_MOVED)) {
            return null;
        }

        // press / release (double clicks also arrive as a press, they're detected by the app)
        $pressed = $buttons & ~$this->previousButtons;
        $this->previousButtons = $buttons;
        if ($pressed & self::FROM_LEFT_1ST_BUTTON_PRESSED) {
            return Key::mouse('click', $x, $y);
        }
        if ($pressed & self::RIGHTMOST_BUTTON_PRESSED) {
            return Key::mouse('rightclick', $x, $y);
        }

        return null;
    }

    private function translate(int $virtualKey, int $unicode, int $state): ?Key
    {
        $ctrl = ($state & (self::LEFT_CTRL_PRESSED | self::RIGHT_CTRL_PRESSED)) !== 0;
        $alt = ($state & (self::LEFT_ALT_PRESSED | self::RIGHT_ALT_PRESSED)) !== 0;
        $shift = ($state & self::SHIFT_PRESSED) !== 0;

        if (isset(self::VIRTUAL_KEYS[$virtualKey])) {
            $name = self::VIRTUAL_KEYS[$virtualKey];
            if ($name === 'tab' && $shift) {
                $name = 'backtab';
            }

            return new Key($name);
        }

        // ctrl + letter (AltGr is reported as ctrl+alt and produces normal characters)
        if ($ctrl && !$alt && $virtualKey >= 0x41 && $virtualKey <= 0x5A) {
            return new Key('ctrl+' . strtolower(chr($virtualKey)));
        }

        if ($unicode === 0) {
            return null;
        }

        // characters outside the BMP arrive as two surrogate halves
        if ($unicode >= 0xD800 && $unicode <= 0xDBFF) {
            $this->pendingHighSurrogate = $unicode;

            return null;
        }
        if ($unicode >= 0xDC00 && $unicode <= 0xDFFF && $this->pendingHighSurrogate !== null) {
            $unicode = 0x10000 + (($this->pendingHighSurrogate - 0xD800) << 10) + ($unicode - 0xDC00);
            $this->pendingHighSurrogate = null;
        }

        if ($unicode < 32) {
            return match ($unicode) {
                13 => new Key('enter'),
                9 => new Key('tab'),
                8 => new Key('backspace'),
                27 => new Key('esc'),
                default => new Key('ctrl+' . chr($unicode + 96)),
            };
        }

        return Key::char(mb_chr($unicode, 'UTF-8'));
    }
}
