<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Storage for the application-level settings that previously lived in
 * config.json (status, max_image_size) and in welcome.md (welcome text).
 *
 * Post-M019 the canonical store is bdus_cfg_app (single row, id = 1).
 * Pre-M019 the values are read from the config.json array passed as $legacy.
 */

namespace Config;

use DB\DBInterface;

class AppSettings
{
    private const TABLE  = 'bdus_cfg_app';
    private const ROW_ID = 1;

    // ── Availability ─────────────────────────────────────────────────────────

    /**
     * Returns true when bdus_cfg_app exists and has a seeded row.
     */
    public static function isAvailable(DBInterface $db): bool
    {
        try {
            $row = $db->query(
                'SELECT id FROM ' . self::TABLE . ' WHERE id = ?',
                [self::ROW_ID],
                'read'
            );
            return !empty($row);
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * Returns the app settings row as an associative array.
     * Keys: status (string), max_image_size (int), welcome (string).
     *
     * Falls back to sensible defaults when the row is missing.
     */
    public static function get(DBInterface $db): array
    {
        try {
            $rows = $db->query(
                'SELECT status, max_image_size, welcome FROM ' . self::TABLE . ' WHERE id = ?',
                [self::ROW_ID],
                'read'
            );
            if (!empty($rows)) {
                return $rows[0];
            }
        } catch (\Throwable) {
            // Table not yet created — migration has not run.
        }
        return ['status' => 'on', 'max_image_size' => 0, 'welcome' => ''];
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Persists status and max_image_size to bdus_cfg_app.
     *
     * Accepted keys: status, max_image_size.
     * Unknown keys are silently ignored.
     */
    public static function save(DBInterface $db, array $settings): void
    {
        $allowed = ['status', 'max_image_size'];
        $data    = array_intersect_key($settings, array_flip($allowed));

        if (empty($data)) {
            return;
        }

        $setParts = array_map(fn($k) => "{$k} = ?", array_keys($data));
        $db->query(
            'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $setParts) . ' WHERE id = ?',
            [...array_values($data), self::ROW_ID],
            'boolean'
        );
    }

    // ── Welcome text ──────────────────────────────────────────────────────────

    /**
     * Returns the welcome Markdown text (empty string if not set).
     */
    public static function getWelcome(DBInterface $db): string
    {
        try {
            $rows = $db->query(
                'SELECT welcome FROM ' . self::TABLE . ' WHERE id = ?',
                [self::ROW_ID],
                'read'
            );
            return $rows[0]['welcome'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Saves the welcome Markdown text.
     */
    public static function saveWelcome(DBInterface $db, string $content): void
    {
        $db->query(
            'UPDATE ' . self::TABLE . ' SET welcome = ? WHERE id = ?',
            [$content, self::ROW_ID],
            'boolean'
        );
    }
}
