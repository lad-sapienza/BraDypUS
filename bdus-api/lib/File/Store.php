<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace File;

use DB\DBInterface;

/**
 * Where and under which name an uploaded file lives on disk.
 *
 * The single source of truth for the naming convention: a file is stored as
 * `projects/{app}/files/{uuid}.{ext}`, where `uuid` is the random (v4) value of
 * `bdus_files.uuid`. The integer `bdus_files.id` stays the internal key (links,
 * rules, versions); it is never used to build a path or a URL.
 *
 * Everything that creates, finds or removes a binary goes through here, so the
 * convention can be changed in one place.
 */
final class Store
{
    /** Canonical lower-case textual form: 8-4-4-4-12 hex digits. */
    public const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D'; // D: `$` must not accept a trailing newline

    /** A new random (version 4) UUID. */
    public static function newUuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // version 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant RFC 4122
        $h    = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
            . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID_REGEX, $value) === 1;
    }

    /** Folder of the original files of a project (with a trailing slash). */
    public static function dir(string $projDir): string
    {
        return rtrim($projDir, '/') . '/files/';
    }

    /**
     * Path of the binary of a file. The uuid is validated: it ends up in a
     * filesystem path, so anything that is not a canonical UUID is refused.
     *
     * @throws \InvalidArgumentException
     */
    public static function path(string $projDir, string $uuid, string $ext): string
    {
        if (!self::isUuid($uuid)) {
            throw new \InvalidArgumentException('invalid file uuid');
        }
        $ext = strtolower($ext);
        if ($ext !== '' && preg_match('/^[a-z0-9]+$/D', $ext) !== 1) {
            throw new \InvalidArgumentException('invalid file extension');
        }

        return self::dir($projDir) . $uuid . ($ext === '' ? '' : '.' . $ext);
    }

    /**
     * Inserts the `bdus_files` row of a new file and returns its keys.
     * The caller then moves/writes the binary to `path($projDir, $uuid, $ext)`.
     *
     * @return array{id: int, uuid: string}
     * @throws \InvalidArgumentException When the extension is not made of letters and digits.
     */
    public static function create(DBInterface $db, string|int $creator, string $ext, string $filename): array
    {
        $uuid = self::newUuid();
        self::path('', $uuid, $ext); // refuse an unusable extension BEFORE a row exists
        $id   = $db->query(
            'INSERT INTO bdus_files (uuid, creator, ext, filename) VALUES (?, ?, ?, ?)',
            [$uuid, $creator, $ext, $filename],
            'id'
        );

        return ['id' => (int) $id, 'uuid' => $uuid];
    }

    /** The uuid of file `$id`, or null when there is no such file. */
    public static function uuidOf(DBInterface $db, int $id): ?string
    {
        $rows = $db->query('SELECT uuid FROM bdus_files WHERE id = ?', [$id], 'read');

        return $rows ? ((string) $rows[0]['uuid'] ?: null) : null;
    }

    /**
     * Removes the binary (and, when `$projDir` is given, every derived version)
     * of a file. Best effort: a missing file is not an error.
     */
    public static function remove(string $projDir, string $uuid, string $ext): void
    {
        \Image\Derivative::purgeFile($projDir, $uuid);
        try {
            $path = self::path($projDir, $uuid, $ext);
        } catch (\InvalidArgumentException) {
            return; // legacy row with an unusable extension or no uuid: nothing we can safely name
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
