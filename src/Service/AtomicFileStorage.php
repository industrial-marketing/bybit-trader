<?php

namespace App\Service;

/**
 * Atomic JSON file I/O with flock + temp-rename pattern.
 *
 * Prevents JSON corruption and race conditions when multiple PHP processes
 * (concurrent cron runs, manual triggers) read/write the same state files.
 *
 * Pattern:
 *   - Reads  → Lock-free. Write uses .tmp + rename (atomic), so reads never see partial data.
 *   - Writes → LOCK_EX on .lock file, write to .tmp.PID, then rename over target.
 *   - Update → LOCK_EX, re-read inside lock, apply callback, write atomically.
 *
 * Lock-free reads avoid blocking cron writes when the dashboard polls frequently.
 */
class AtomicFileStorage
{
    /**
     * Read a JSON file (lock-free). Safe because writes use atomic rename.
     * Returns decoded array or $default on missing / invalid file.
     */
    public static function read(string $path, array $default = []): array
    {
        if (!file_exists($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        return $raw !== false ? (json_decode($raw, true) ?? $default) : $default;
    }

    /**
     * Write $data as JSON atomically.
     * Uses LOCK_EX to serialise concurrent writers, then temp-rename for atomicity.
     */
    public static function write(string $path, array $data): void
    {
        self::ensureDir($path);

        $lockFh = self::openLock($path);
        if ($lockFh === false) {
            // Fallback: plain write
            file_put_contents($path, self::encode($data));
            return;
        }

        try {
            flock($lockFh, LOCK_EX);
            self::atomicWrite($path, $data);
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    /**
     * Read-modify-write under an exclusive lock.
     *
     * $callback receives the current array and must return the new array.
     * The file is re-read *inside* the lock so stale in-memory state can't
     * cause lost updates.
     *
     * Returns the array that was ultimately written.
     */
    public static function update(string $path, callable $callback, array $default = []): array
    {
        self::ensureDir($path);

        $lockFh = self::openLock($path);
        if ($lockFh === false) {
            // Fallback: non-atomic update
            $current = self::read($path, $default);
            $result  = $callback($current);
            file_put_contents($path, self::encode($result));
            return $result;
        }

        try {
            flock($lockFh, LOCK_EX);

            // Re-read inside the lock to get the latest state
            $current = file_exists($path)
                ? (json_decode(@file_get_contents($path), true) ?? $default)
                : $default;

            $result = $callback($current);
            self::atomicWrite($path, $result);
            return $result;
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    // ── Private helpers ────────────────────────────────────────────

    /** Open (or create) the companion .lock file. Returns false on failure. */
    private static function openLock(string $path): mixed
    {
        self::ensureDir($path);
        return @fopen($path . '.lock', 'c+');
    }

    /** Write $data to a .tmp.PID file then rename over $path. */
    private static function atomicWrite(string $path, array $data): void
    {
        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, self::encode($data));
        rename($tmp, $path);
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function ensureDir(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
