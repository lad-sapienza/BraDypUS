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
     * Keys: status (string), max_image_size (int), welcome (string), lang (string).
     *
     * Falls back to sensible defaults when the row is missing.
     */
    public static function get(DBInterface $db): array
    {
        try {
            $rows = $db->query(
                'SELECT status, max_image_size, welcome, color, bdus_version, allow_self_registration, lang FROM ' . self::TABLE . ' WHERE id = ?',
                [self::ROW_ID],
                'read'
            );
            if (!empty($rows)) {
                return $rows[0];
            }
        } catch (\Throwable) {
            // Table not yet created, or allow_self_registration/lang column not
            // yet added by M041/M042 — either way, fall back to the safe defaults
            // below rather than break every caller until the admin applies the upgrade.
        }
        return ['status' => 'on', 'max_image_size' => 0, 'welcome' => '', 'color' => 'indigo', 'bdus_version' => null, 'allow_self_registration' => 0, 'lang' => 'en'];
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Persists status, max_image_size, color, allow_self_registration and lang
     * to bdus_cfg_app.
     *
     * Accepted keys: status, max_image_size, color, allow_self_registration, lang.
     * Unknown keys are silently ignored.
     */
    public static function save(DBInterface $db, array $settings): void
    {
        $allowed = ['status', 'max_image_size', 'color', 'allow_self_registration', 'lang'];
        $data    = array_intersect_key($settings, array_flip($allowed));

        if (isset($data['allow_self_registration'])) {
            $data['allow_self_registration'] = $data['allow_self_registration'] ? 1 : 0;
        }

        if (empty($data)) {
            return;
        }

        $setParts = array_map(fn($k) => "{$k} = ?", array_keys($data));
        try {
            $db->query(
                'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $setParts) . ' WHERE id = ?',
                [...array_values($data), self::ROW_ID],
                'boolean'
            );
        } catch (\Throwable) {
            // allow_self_registration/lang column not yet added by M041/M042 —
            // no-op until the admin applies the pending upgrade, rather than error.
        }
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
