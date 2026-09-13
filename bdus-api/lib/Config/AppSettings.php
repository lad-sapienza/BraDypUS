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
     * Keys: status (string), max_image_size (int), welcome (string), lang (string),
     * image_convert (int 0/1), image_format ('webp'|'jpg'), image_quality (int),
     * image_dpi (int).
     *
     * Falls back to sensible defaults when the row is missing.
     */
    public static function get(DBInterface $db): array
    {
        try {
            $rows = $db->query(
                'SELECT status, max_image_size, welcome, color, bdus_version, allow_self_registration, lang,
                        image_convert, image_format, image_quality, image_dpi
                   FROM ' . self::TABLE . ' WHERE id = ?',
                [self::ROW_ID],
                'read'
            );
            if (!empty($rows)) {
                return $rows[0];
            }
        } catch (\Throwable) {
            // Table not yet created, or a column not yet added by a pending
            // migration (M041/M042/M045) — either way, fall back to the safe
            // defaults below rather than break every caller until the admin
            // applies the upgrade.
        }
        return [
            'status'                  => 'on',
            'max_image_size'          => 0,
            'welcome'                 => '',
            'color'                   => 'indigo',
            'bdus_version'            => null,
            'allow_self_registration' => 0,
            'lang'                    => 'en',
            'image_convert'           => 0,
            'image_format'            => 'webp',
            'image_quality'           => 85,
            'image_dpi'               => 72,
        ];
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Persists status, max_image_size, color, allow_self_registration, lang and
     * the image-conversion settings to bdus_cfg_app.
     *
     * Accepted keys: status, max_image_size, color, allow_self_registration, lang,
     * image_convert, image_format, image_quality, image_dpi.
     * Unknown keys are silently ignored.
     */
    public static function save(DBInterface $db, array $settings): void
    {
        $allowed = [
            'status', 'max_image_size', 'color', 'allow_self_registration', 'lang',
            'image_convert', 'image_format', 'image_quality', 'image_dpi',
        ];
        $data    = array_intersect_key($settings, array_flip($allowed));

        if (isset($data['allow_self_registration'])) {
            $data['allow_self_registration'] = $data['allow_self_registration'] ? 1 : 0;
        }
        if (isset($data['image_convert'])) {
            $data['image_convert'] = $data['image_convert'] ? 1 : 0;
        }
        if (isset($data['image_format']) && $data['image_format'] !== 'jpg') {
            $data['image_format'] = 'webp';
        }
        if (isset($data['image_quality'])) {
            $data['image_quality'] = max(1, min(100, (int) $data['image_quality']));
        }
        if (isset($data['image_dpi'])) {
            $data['image_dpi'] = max(1, (int) $data['image_dpi']);
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
            // A column not yet added by a pending migration (M041/M042/M045) —
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
