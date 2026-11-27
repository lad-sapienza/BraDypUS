<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace File;

use Config\Config;
use DB\DBInterface;

/**
 * Finds the addresses of the old scheme inside the data: `projects/{app}/files/{id}.{ext}` and
 * `/api/file/{preset}/{id}.{webp|jpg}`. Files are now addressed by their uuid, so a link of the old
 * kind typed into a text field, a template or the welcome text no longer works. Read-only: it only
 * reports; fixing is a decision for a person (the right replacement depends on what the link is for).
 */
final class LegacyUrls
{
    /** An old-style address: the file named by its integer id. */
    // The id must END there: `files/3f2b8c1e-…` is a uuid that merely starts with digits, not an old address.
    public const PATTERN = '#(?:projects/[A-Za-z0-9_.-]+/files/\d+(?:\.[A-Za-z0-9]+)?(?![A-Za-z0-9_-])|/api/file/[a-z0-9][a-z0-9_-]*/\d+\.(?:webp|jpg)(?![A-Za-z0-9_-]))#';

    private const TEXT_TYPES = ['text', 'long_text', 'md'];

    /** The old-style addresses in a piece of text. @return list<string> */
    public static function find(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        return preg_match_all(self::PATTERN, $text, $m) ? array_values(array_unique($m[0])) : [];
    }

    /**
     * Scans the text fields of every configured data table, the welcome text and the print templates.
     *
     * @return list<array{where: string, id: int|null, field: string, found: list<string>}>
     */
    public static function scan(DBInterface $db, Config $cfg, int $limit = 500): array
    {
        $out  = [];
        $like = \SQL\Like::operator($db->getEngine());

        foreach ((array) ($cfg->get('tables.*.name') ?: []) as $tb) {
            if (!is_string($tb) || str_starts_with($tb, 'bdus_') || preg_match('/^[A-Za-z0-9_]+$/', $tb) !== 1) {
                continue;
            }
            foreach ((array) ($cfg->get("tables.{$tb}.fields") ?: []) as $fld) {
                $col = $fld['name'] ?? null;
                if (!is_string($col) || preg_match('/^[A-Za-z0-9_]+$/', $col) !== 1
                    || !in_array($fld['type'] ?? 'text', self::TEXT_TYPES, true)) {
                    continue;
                }
                try {
                    $rows = $db->query(
                        "SELECT id, {$col} AS v FROM {$tb} WHERE {$col} {$like} ? OR {$col} {$like} ?",
                        ['%/files/%', '%api/file/%'],
                        'read'
                    ) ?: [];
                } catch (\Throwable) {
                    continue; // a configured field that is not a column (yet): nothing to scan
                }
                foreach ($rows as $r) {
                    if ($found = self::find((string) $r['v'])) {
                        $out[] = ['where' => $tb, 'id' => (int) $r['id'], 'field' => $col, 'found' => $found];
                        if (count($out) >= $limit) {
                            return $out;
                        }
                    }
                }
            }
        }

        foreach ([
            ['bdus_cfg_app', 'welcome', null],
            ['bdus_cfg_templates', 'content', 'id'],
        ] as [$tb, $col, $idCol]) {
            try {
                foreach ($db->query("SELECT " . ($idCol ?? '1') . " AS id, {$col} AS v FROM {$tb}", [], 'read') ?: [] as $r) {
                    if ($found = self::find((string) $r['v'])) {
                        $out[] = ['where' => $tb, 'id' => $idCol ? (int) $r['id'] : null, 'field' => $col, 'found' => $found];
                    }
                }
            } catch (\Throwable) {
                // an older application without that table: nothing to scan
            }
        }

        return array_slice($out, 0, $limit);
    }
}
