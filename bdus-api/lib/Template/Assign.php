<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Template;

use DB\DBInterface;

/**
 * Record layouts per group.
 *
 * A group can name, per table, the templates its members must use
 * (`bdus_groups.layouts`, JSON {table: [template, ...]}). This is a convenience
 * of the interface, NOT a permission: the layout decides what is comfortable to
 * see and edit, the groups' rules decide what is allowed. Every endpoint still
 * returns the same data whatever the layout.
 *
 * Resolution for one principal and one table: the layouts of all their groups
 * are united (groups by id, templates in the order listed), entries naming a
 * template that no longer exists are ignored, and an empty result means "no
 * restriction" (the user chooses freely, as before).
 */
class Assign
{
    /**
     * Templates the principal's groups assign for $tb, or null for "no restriction".
     *
     * @param int[] $groupIds
     * @return string[]|null  never an empty list
     */
    public static function allowed(DBInterface $db, string $appName, string $tb, array $groupIds): ?array
    {
        $groupIds = array_values(array_filter(array_map('intval', $groupIds), fn($g) => $g > 0));
        if (!$groupIds) {
            return null;
        }
        $marks = implode(',', array_fill(0, count($groupIds), '?'));
        try {
            $rows = $db->query(
                "SELECT layouts FROM bdus_groups WHERE id IN ({$marks}) ORDER BY id",
                $groupIds,
                'read'
            ) ?: [];
        } catch (\Throwable $e) {
            return null; // M051 pending: no layouts
        }

        $names = [];
        foreach ($rows as $row) {
            $layouts = json_decode((string) ($row['layouts'] ?? ''), true);
            foreach ((is_array($layouts) ? ($layouts[$tb] ?? []) : []) as $name) {
                if (is_string($name) && $name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }
        if (!$names) {
            return null;
        }

        $existing = Loader::listAvailable($appName, $tb);
        $names    = array_values(array_filter($names, fn($n) => in_array($n, $existing, true)));
        return $names ?: null;
    }

    /**
     * Validates a {table: [template, ...]} request value.
     *
     * @param  string[] $tables  data tables that may appear
     * @return array{0: ?array, 1: ?string}  [normalised layouts, error code]; empty tables are dropped
     */
    public static function normalise(mixed $layouts, array $tables, string $appName): array
    {
        if ($layouts === null || $layouts === [] ) {
            return [[], null];
        }
        if (!is_array($layouts) || array_is_list($layouts)) {
            return [null, 'invalid_layouts'];
        }
        $out = [];
        foreach ($layouts as $tb => $names) {
            if (!is_string($tb) || !in_array($tb, $tables, true)) {
                return [null, 'unknown_table'];
            }
            if (!is_array($names) || !array_is_list($names)) {
                return [null, 'invalid_layouts'];
            }
            $existing = Loader::listAvailable($appName, $tb);
            $clean    = [];
            foreach ($names as $name) {
                if (!is_string($name) || !in_array($name, $existing, true)) {
                    return [null, 'template_not_found'];
                }
                if (!in_array($name, $clean, true)) {
                    $clean[] = $name;
                }
            }
            if ($clean) {
                $out[$tb] = $clean;
            }
        }
        return [$out, null];
    }

    /** A template was renamed: follow it in every group. */
    public static function renamed(DBInterface $db, string $tb, string $old, string $new): void
    {
        self::rewrite($db, $tb, fn(array $names) => array_values(array_unique(
            array_map(fn($n) => $n === $old ? $new : $n, $names)
        )));
    }

    /** A template was deleted: drop it from every group. */
    public static function removed(DBInterface $db, string $tb, string $name): void
    {
        self::rewrite($db, $tb, fn(array $names) => array_values(array_diff($names, [$name])));
    }

    private static function rewrite(DBInterface $db, string $tb, callable $change): void
    {
        try {
            $rows = $db->query("SELECT id, layouts FROM bdus_groups WHERE layouts IS NOT NULL", [], 'read') ?: [];
        } catch (\Throwable $e) {
            return; // M051 pending
        }
        foreach ($rows as $row) {
            $layouts = json_decode((string) $row['layouts'], true);
            if (!is_array($layouts) || !isset($layouts[$tb]) || !is_array($layouts[$tb])) {
                continue;
            }
            $names = $change($layouts[$tb]);
            if ($names) {
                $layouts[$tb] = $names;
            } else {
                unset($layouts[$tb]);
            }
            $db->query(
                'UPDATE bdus_groups SET layouts = ? WHERE id = ?',
                [$layouts ? json_encode($layouts, JSON_UNESCAPED_UNICODE) : null, (int) $row['id']],
                'boolean'
            );
        }
    }
}
