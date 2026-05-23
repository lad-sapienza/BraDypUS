<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Template storage: from v5.1 templates live in bdus_cfg_templates (DB).
 * Apps migrated from an older version have their templates imported by M011.
 * Filesystem fallback is kept for test environments that inject a DB-less config.
 */

namespace Template;

use DB\DBInterface;

/**
 * Loads, lists, saves, and validates JSON record-view templates.
 *
 * Storage resolution (checked in order):
 *   1. DB   — bdus_cfg_templates table (preferred, post-M011)
 *   2. File — {projectsRoot}{appName}/template/{tb}.{name}.json (legacy / tests)
 */
class Loader
{
    /** Base directory for all app project files. Overridable for tests. */
    private static string $projectsRoot = 'projects/';

    /** Injected DB instance (set by App after boot). Null = file-only mode. */
    private static ?DBInterface $db = null;

    // ── Configuration ─────────────────────────────────────────────────────────

    public static function setProjectsRoot(string $root): void
    {
        self::$projectsRoot = $root;
    }

    public static function setDb(?DBInterface $db): void
    {
        self::$db = $db;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Load and JSON-decode a template.
     *
     * @return array|null  Decoded template, or null if not found / invalid.
     */
    public static function load(
        string $appName,
        string $tb,
        string $templateName,
        string $projectsRoot = null
    ): ?array {
        // DB first.
        if (self::$db) {
            $row = self::$db->query(
                'SELECT content FROM bdus_cfg_templates WHERE table_name=? AND name=?',
                [$tb, $templateName],
                'read'
            );
            if ($row) {
                try {
                    $decoded = json_decode($row[0]['content'], true, 512, JSON_THROW_ON_ERROR);
                    return is_array($decoded) ? $decoded : null;
                } catch (\JsonException $e) {
                    return null;
                }
            }
        }

        // Filesystem fallback.
        $path = self::path($appName, $tb, $templateName, $projectsRoot);
        if (!is_file($path)) return null;

        $raw = file_get_contents($path);
        if ($raw === false) return null;

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * List all available template names for a given table.
     *
     * @return string[]  Sorted array of template names (without prefix/suffix).
     */
    public static function listAvailable(
        string $appName,
        string $tb,
        string $projectsRoot = null
    ): array {
        if (self::$db) {
            $rows = self::$db->query(
                'SELECT name FROM bdus_cfg_templates WHERE table_name=? ORDER BY name',
                [$tb],
                'read'
            ) ?: [];
            return array_column($rows, 'name');
        }

        // Filesystem fallback.
        $dir = self::dir($appName, $projectsRoot);
        if (!is_dir($dir)) return [];

        $prefix = $tb . '.';
        $suffix = '.json';
        $names  = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (str_starts_with($entry, $prefix) && str_ends_with($entry, $suffix)) {
                $name = substr($entry, strlen($prefix), -strlen($suffix));
                if ($name !== '') $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /**
     * Save (insert or replace) a template.
     * Returns true on success, false on failure.
     */
    public static function save(
        string $appName,
        string $tb,
        string $templateName,
        array $payload
    ): bool {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (self::$db) {
            try {
                $existing = self::$db->query(
                    'SELECT id FROM bdus_cfg_templates WHERE table_name=? AND name=?',
                    [$tb, $templateName],
                    'read'
                );
                if ($existing) {
                    self::$db->query(
                        'UPDATE bdus_cfg_templates SET content=?, updated_at=? WHERE table_name=? AND name=?',
                        [$json, date('Y-m-d H:i:s'), $tb, $templateName],
                        'boolean'
                    );
                } else {
                    self::$db->query(
                        'INSERT INTO bdus_cfg_templates (table_name, name, content, updated_at) VALUES (?,?,?,?)',
                        [$tb, $templateName, $json, date('Y-m-d H:i:s')],
                        'boolean'
                    );
                }
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        }

        // Filesystem fallback.
        $dir  = self::dir($appName, null);
        $path = self::path($appName, $tb, $templateName, null);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;
        return file_put_contents($path, $json) !== false;
    }

    /**
     * Check whether a template exists.
     */
    public static function exists(string $appName, string $tb, string $templateName): bool
    {
        if (self::$db) {
            $row = self::$db->query(
                'SELECT id FROM bdus_cfg_templates WHERE table_name=? AND name=?',
                [$tb, $templateName],
                'read'
            );
            return !empty($row);
        }
        return is_file(self::path($appName, $tb, $templateName, null));
    }

    /**
     * Delete a template. Returns false if not found.
     */
    public static function delete(string $appName, string $tb, string $templateName): bool
    {
        if (self::$db) {
            if (!self::exists($appName, $tb, $templateName)) return false;
            self::$db->query(
                'DELETE FROM bdus_cfg_templates WHERE table_name=? AND name=?',
                [$tb, $templateName],
                'boolean'
            );
            return true;
        }
        $path = self::path($appName, $tb, $templateName, null);
        if (!file_exists($path)) return false;
        return unlink($path);
    }

    /**
     * Rename a template (copy content, delete old row/file).
     */
    public static function rename(
        string $appName,
        string $tb,
        string $oldName,
        string $newName
    ): bool {
        if (self::$db) {
            try {
                self::$db->query(
                    'UPDATE bdus_cfg_templates SET name=? WHERE table_name=? AND name=?',
                    [$newName, $tb, $oldName],
                    'boolean'
                );
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        }
        $oldPath = self::path($appName, $tb, $oldName, null);
        $newPath = self::path($appName, $tb, $newName, null);
        return @rename($oldPath, $newPath);
    }

    /**
     * Validate a decoded template array.
     *
     * @param  array    $template     The decoded template.
     * @param  string[] $fieldNames   Valid core field names for the table.
     * @param  string[] $pluginNames  Valid plugin table IDs for the table.
     * @return string[]               Error strings; empty = valid.
     */
    public static function validate(array $template, array $fieldNames, array $pluginNames): array
    {
        $errors = [];

        if (!array_key_exists('sections', $template) || !is_array($template['sections'])) {
            $errors[] = 'sections_missing_or_invalid';
            return $errors;
        }

        $validWidths = ['1/1', '1/2', '1/3', '2/3', '1/4', '3/4'];

        foreach ($template['sections'] as $idx => $section) {
            $sectionLabel = "Section[{$idx}]";

            if (!array_key_exists('content', $section) || !is_array($section['content'])) {
                $errors[] = "{$sectionLabel}: content_missing_or_invalid";
                continue;
            }

            $isPlugin = array_key_exists('plugin', $section);
            if ($isPlugin) {
                if (!in_array($section['plugin'], $pluginNames, true)) {
                    $errors[] = "{$sectionLabel}: unknown_plugin '{$section['plugin']}'";
                }
            } else {
                foreach ($section['content'] as $itemIdx => $item) {
                    if (!isset($item['field'])) continue;
                    if (!in_array($item['field'], $fieldNames, true)) {
                        $errors[] = "{$sectionLabel}[{$itemIdx}]: unknown_field '{$item['field']}'";
                    }
                }
            }

            foreach ($section['content'] as $itemIdx => $item) {
                if (isset($item['width']) && !in_array($item['width'], $validWidths, true)) {
                    $errors[] = "{$sectionLabel}[{$itemIdx}]: invalid_width '{$item['width']}'";
                }
            }
        }

        return $errors;
    }

    // ── Public helpers ────────────────────────────────────────────────────────

    public static function getDir(string $appName): string
    {
        return self::dir($appName, null);
    }

    public static function getPath(string $appName, string $tb, string $templateName): string
    {
        return self::path($appName, $tb, $templateName, null);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function path(
        string $appName,
        string $tb,
        string $templateName,
        ?string $projectsRoot
    ): string {
        return self::dir($appName, $projectsRoot) . $tb . '.' . $templateName . '.json';
    }

    private static function dir(string $appName, ?string $projectsRoot): string
    {
        $root = $projectsRoot ?? self::$projectsRoot;
        if (!str_ends_with($root, '/')) $root .= '/';
        return $root . $appName . '/template/';
    }
}
