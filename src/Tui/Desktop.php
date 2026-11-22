<?php

namespace App\Tui;

use FFI;
use Throwable;

/**
 * Integration with the desktop environment: opening links in the browser and copying text to the clipboard.
 */
final class Desktop
{
    /**
     * shell32 and ole32 stay loaded for the lifetime of the process: ShellExecute leaves threads running code from
     * these libraries, and unloading them (which happens when the FFI instance is freed) crashes the process
     */
    private static ?FFI $shell32 = null;

    private static ?FFI $ole32 = null;

    /**
     * @return bool whether the link was handed to the system successfully
     */
    public static function openUrl(string $url): bool
    {
        if (PHP_OS_FAMILY === 'Windows' && self::shellExecute($url)) {
            return true;
        }

        // an array command bypasses the shell, so the & and % characters in the url are passed as is
        $command = match (PHP_OS_FAMILY) {
            'Windows' => ['explorer.exe', $url],
            'Darwin' => ['open', $url],
            default => ['xdg-open', $url],
        };

        // explorer.exe exits with 1 even when it succeeded, so only the start of the process can be checked
        return self::run($command, null, PHP_OS_FAMILY !== 'Windows');
    }

    /**
     * @return bool whether the text was copied
     */
    public static function copyToClipboard(string $text): bool
    {
        $commands = match (PHP_OS_FAMILY) {
            'Windows' => [['clip.exe']],
            'Darwin' => [['pbcopy']],
            default => [['wl-copy'], ['xclip', '-selection', 'clipboard'], ['xsel', '--clipboard', '--input']],
        };
        foreach ($commands as $command) {
            if (self::run($command, $text, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ShellExecute is what Windows itself uses to open links, it respects the default browser.
     */
    private static function shellExecute(string $url): bool
    {
        if (!extension_loaded('ffi')) {
            return false;
        }

        try {
            if (self::$shell32 === null) {
                self::$ole32 = FFI::cdef('long CoInitializeEx(void* pvReserved, unsigned long dwCoInit);', 'ole32.dll');
                // COINIT_APARTMENTTHREADED | COINIT_DISABLE_OLE1DDE, as recommended for ShellExecute
                self::$ole32->CoInitializeEx(null, 0x2 | 0x4);
                self::$shell32 = FFI::cdef(
                    'typedef unsigned long long uintptr_t;'
                    . 'uintptr_t ShellExecuteW(void* hwnd, const unsigned short* lpOperation, const unsigned short* lpFile, const unsigned short* lpParameters, const unsigned short* lpDirectory, int nShowCmd);',
                    'shell32.dll',
                );
            }
            $operation = self::wideString(self::$shell32, 'open');
            $file = self::wideString(self::$shell32, $url);

            // SW_SHOWNORMAL; values above 32 indicate success
            return self::$shell32->ShellExecuteW(null, $operation, $file, null, null, 1) > 32;
        } catch (Throwable) {
            return false;
        }
    }

    private static function wideString(FFI $ffi, string $value): FFI\CData
    {
        $encoded = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8') . "\0\0";
        $length = intdiv(strlen($encoded), 2);
        $buffer = $ffi->new("unsigned short[{$length}]");
        FFI::memcpy($buffer, $encoded, strlen($encoded));

        return $buffer;
    }

    /**
     * @param list<string> $command
     */
    private static function run(array $command, ?string $input, bool $checkExitCode): bool
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => $input === null ? ['file', $null, 'r'] : ['pipe', 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes);
        if ($process === false) {
            return false;
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
        }
        $exitCode = proc_close($process);

        return !$checkExitCode || $exitCode === 0;
    }
}
