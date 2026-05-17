<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Stateless per-request preference store.
 *
 * In v5 there are no PHP sessions. Preferences that used to live in
 * $_SESSION['pref'] are now held in static arrays for the duration of
 * the current HTTP request only. Long-lived user preferences (templates,
 * preview fields) will be migrated to the users.settings JSON column or
 * to the Vue / localStorage layer as v5 modules replace their v4 counterparts.
 */

class pref
{
    /** @var string|null  current resolved language code */
    private static ?string $lang = null;

    /** @var array<string,mixed>  generic key-value preferences */
    private static array $data = [];

    /** @var array<string,array<string,string>>  per-table templates  [tb][context] */
    private static array $tmpls = [];

    // ── Language ─────────────────────────────────────────────────────────

    public static function getLang(): ?string
    {
        return self::$lang;
    }

    public static function setLang(string $lang): void
    {
        self::$lang = $lang;
    }

    // ── Generic preferences ──────────────────────────────────────────────

    public static function get(string $key): mixed
    {
        return self::$data[$key] ?? null;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
    }

    // ── Record templates ─────────────────────────────────────────────────

    public static function getTmpl(string $tb, string $context): ?string
    {
        return self::$tmpls[$tb][$context] ?? null;
    }

    public static function setTmpl(string $tb, string $context, string $tmpl): void
    {
        self::$tmpls[$tb][$context] = $tmpl;
    }
}
