<?php
declare(strict_types=1);

// Work folders of tests and screenshot tools live in <PF_TMP_DIR or E:\Testing fashion store\.tmp>, never in %TEMP% (C: filled up once).
// dir() folders and tracked processes (PHP server, Chrome) are removed / killed by PID when the script ends, also after a failure or exit().
final class Tmp
{
    /** @var list<string> */
    private static array $dirs = [];
    /** @var list<resource> */
    private static array $procs = [];
    private static bool $hooked = false;

    public static function base(): string
    {
        $base = getenv('PF_TMP_DIR') ?: dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '.tmp';
        if (!is_dir($base)) {
            mkdir($base, 0775, true);
        }
        return $base;
    }

    /** A new work folder, removed when the script ends. */
    public static function dir(string $prefix): string
    {
        self::hook();
        $dir = self::base() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        return self::$dirs[] = $dir;
    }

    /** The one shared Chrome profile (one sub-folder per $slot when several Chromes run at once), emptied first so every page load starts clean. */
    public static function profile(string $slot = ''): string
    {
        $dir = self::base() . DIRECTORY_SEPARATOR . 'chrome-profile' . ($slot === '' ? '' : DIRECTORY_SEPARATOR . $slot);
        self::rm($dir);
        mkdir($dir, 0775, true);
        return $dir;
    }

    /** @param resource $proc */
    public static function track($proc): void
    {
        self::hook();
        self::$procs[] = $proc;
    }

    /** Kills a process and everything it started. @param resource $proc */
    public static function kill($proc): void
    {
        if (!is_resource($proc)) {
            return;
        }
        $pid = (int) (proc_get_status($proc)['pid'] ?? 0);
        @proc_terminate($proc);
        if ($pid > 0) {
            @exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>&1');
        }
        @proc_close($proc);
    }

    public static function rm(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);
            return;
        }
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $e) {
            if ($e !== '.' && $e !== '..') {
                self::rm($dir . DIRECTORY_SEPARATOR . $e);
            }
        }
        @rmdir($dir);
    }

    private static function hook(): void
    {
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function(static function (): void {
                foreach (self::$procs as $p) {
                    self::kill($p);
                }
                foreach (self::$dirs as $d) {
                    self::rm($d);
                }
                self::rm(self::base() . DIRECTORY_SEPARATOR . 'chrome-profile');
            });
        }
    }
}
