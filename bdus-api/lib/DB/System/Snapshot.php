<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System;

/**
 * Takes a pre-upgrade snapshot of a project directory so a failed or
 * unsatisfactory v4 -> v5 major upgrade can be rolled back.
 *
 * Two artifacts land in <projDir>/backups/ :
 *
 *   pre-upgrade-<ts>.tar.gz   full rollback archive — everything under the
 *                             project dir EXCEPT files/, cache/, backups/ and
 *                             export/. The major upgrade never touches those
 *                             (it mutates db/, cfg/, config.json and template/),
 *                             so excluding them keeps the archive to roughly the
 *                             size of the SQLite database.
 *
 *   pre-upgrade-<ts>.sqlite   raw copy of db/bdus.sqlite, handed to
 *                             DB\Verify\MigrationVerifier afterwards as the v4
 *                             baseline for the row-count diff. Best-effort.
 *
 * To restore: stop the app, extract the tar.gz over projects/<app>/, done.
 */
final class Snapshot
{
    /** Sub-directories the major upgrade never mutates (or that hold this run's own output). */
    private const EXCLUDE = ['files', 'cache', 'backups', 'export', 'migrations'];

    /**
     * @return array{archive:string, sqlite:?string, ts:string}
     * @throws \RuntimeException when the rollback archive cannot be written
     */
    public static function takeProjectSnapshot(string $projDir): array
    {
        $projDir = rtrim($projDir, '/') . '/';
        if (!is_dir($projDir)) {
            throw new \RuntimeException("project directory not found: {$projDir}");
        }

        $backups = $projDir . 'backups/';
        if (!is_dir($backups) && !@mkdir($backups, 0775, true) && !is_dir($backups)) {
            throw new \RuntimeException("cannot create {$backups}");
        }
        if (!is_writable($backups)) {
            throw new \RuntimeException("{$backups} is not writable");
        }

        $ts      = date('Ymd-His');
        $archive = $backups . "pre-upgrade-{$ts}.tar.gz";

        $ok = self::tarWithShell($projDir, $archive)
           || self::tarWithPhar($projDir, $archive);

        if (!$ok || !is_file($archive) || filesize($archive) === 0) {
            @unlink($archive);
            throw new \RuntimeException("could not create snapshot archive at {$archive}");
        }

        // Raw SQLite copy for the post-upgrade verifier baseline (best-effort —
        // its absence only means the row-count diff is skipped).
        $sqliteDst = null;
        $sqliteSrc = $projDir . 'db/bdus.sqlite';
        if (is_file($sqliteSrc)) {
            $candidate = $backups . "pre-upgrade-{$ts}.sqlite";
            if (@copy($sqliteSrc, $candidate) && is_file($candidate)) {
                $sqliteDst = $candidate;
            }
        }

        return ['archive' => $archive, 'sqlite' => $sqliteDst, 'ts' => $ts];
    }

    // ── implementations ──────────────────────────────────────────────────────

    private static function tarWithShell(string $projDir, string $archive): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $excludes = '';
        foreach (self::EXCLUDE as $d) {
            $excludes .= ' --exclude=' . escapeshellarg('./' . $d);
        }

        $cmd = 'tar -czf ' . escapeshellarg($archive)
             . ' -C ' . escapeshellarg(rtrim($projDir, '/'))
             . $excludes . ' . 2>/dev/null';

        $out = [];
        $rc  = 1;
        @exec($cmd, $out, $rc);

        return $rc === 0 && is_file($archive) && filesize($archive) > 0;
    }

    private static function tarWithPhar(string $projDir, string $archive): bool
    {
        $tarPath = preg_replace('/\.gz$/', '', $archive);
        @unlink($tarPath);
        @unlink($archive);

        try {
            $phar    = new \PharData($tarPath);
            $exclude = array_flip(self::EXCLUDE);
            $base    = rtrim($projDir, '/');

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel = ltrim(substr($file->getPathname(), strlen($base)), '/');
                if (isset($exclude[explode('/', $rel)[0]])) {
                    continue;
                }
                $phar->addFile($file->getPathname(), $rel);
            }

            $phar->compress(\Phar::GZ); // writes <tarPath>.gz == $archive
            unset($phar);
        } catch (\Throwable $e) {
            @unlink($tarPath);
            @unlink($archive);
            return false;
        }

        @unlink($tarPath);
        return is_file($archive) && filesize($archive) > 0;
    }
}
