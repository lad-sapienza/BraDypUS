<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Template;

/**
 * Loads, lists, and validates JSON record-view templates.
 *
 * Template files are stored at:
 *   {projectsRoot}{appName}/template/{tbStripped}.{templateName}.json
 */
class Loader
{
    /** Base directory for all app project files. Can be overridden for tests. */
    private static string $projectsRoot = 'projects/';

    /**
     * Override the base projects root. Primarily for test environments.
     */
    public static function setProjectsRoot(string $root): void
    {
        self::$projectsRoot = $root;
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Load and JSON-decode a template file.
     *
     * @return array|null  Decoded template array, or null if not found / invalid JSON.
     */
    public static function load(
        string $appName,
        string $tbStripped,
        string $templateName,
        string $projectsRoot = null
    ): ?array {
        $path = self::path($appName, $tbStripped, $templateName, $projectsRoot);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

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
        string $tbStripped,
        string $projectsRoot = null
    ): array {
        $dir = self::dir($appName, $projectsRoot);
        if (!is_dir($dir)) {
            return [];
        }

        $prefix = $tbStripped . '.';
        $suffix = '.json';
        $names  = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (str_starts_with($entry, $prefix) && str_ends_with($entry, $suffix)) {
                $name = substr($entry, strlen($prefix), -strlen($suffix));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        sort($names);
        return $names;
    }

    /**
     * Validate a decoded template array.
     *
     * @param  array    $template     The decoded template (from ::load()).
     * @param  string[] $fieldNames   Valid core field names for the table.
     * @param  string[] $pluginNames  Valid plugin table IDs for the table.
     * @return string[]               Error strings; empty = valid.
     */
    public static function validate(array $template, array $fieldNames, array $pluginNames): array
    {
        $errors = [];

        if (!array_key_exists('sections', $template) || !is_array($template['sections'])) {
            $errors[] = 'sections_missing_or_invalid';
            return $errors; // cannot continue without sections
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
                // Validate that plugin value exists in pluginNames
                if (!in_array($section['plugin'], $pluginNames, true)) {
                    $errors[] = "{$sectionLabel}: unknown_plugin '{$section['plugin']}'";
                }
                // Do NOT validate field names within plugin content
            } else {
                // Core section: validate each field name
                foreach ($section['content'] as $itemIdx => $item) {
                    if (!isset($item['field'])) {
                        continue;
                    }
                    if (!in_array($item['field'], $fieldNames, true)) {
                        $errors[] = "{$sectionLabel}[{$itemIdx}]: unknown_field '{$item['field']}'";
                    }
                }
            }

            // Validate widths for all content items (both core and plugin sections)
            foreach ($section['content'] as $itemIdx => $item) {
                if (isset($item['width']) && !in_array($item['width'], $validWidths, true)) {
                    $errors[] = "{$sectionLabel}[{$itemIdx}]: invalid_width '{$item['width']}'";
                }
            }
        }

        return $errors;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Full path to a specific template file.
     */
    private static function path(
        string $appName,
        string $tbStripped,
        string $templateName,
        ?string $projectsRoot
    ): string {
        return self::dir($appName, $projectsRoot) . $tbStripped . '.' . $templateName . '.json';
    }

    /**
     * Directory that holds templates for an app.
     */
    private static function dir(string $appName, ?string $projectsRoot): string
    {
        $root = $projectsRoot ?? self::$projectsRoot;
        // Ensure trailing slash
        if (!str_ends_with($root, '/')) {
            $root .= '/';
        }
        return $root . $appName . '/template/';
    }
}
