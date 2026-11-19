<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace SQL;

/**
 * Accent-insensitive text search (#82), switched on with the environment variable
 * BRADYPUS_ACCENT_INSENSITIVE (1 / true / on). Off by default: comparing through a function
 * defeats indexes and costs time on large tables, so it is opt-in.
 *
 *  - SQLite     : the user function `bdus_fold()` (Unicode decomposition, combining marks
 *                 dropped, lower case), registered on every connection; needs PHP's `intl`.
 *  - PostgreSQL : the `unaccent` extension, when it is installed in the database.
 *  - MySQL / MariaDB: nothing to do here; accents follow the collation of the column
 *                 (`…_ci` ignores them, `…_bin` does not).
 *
 * When the switch is on but the engine cannot fold (no `intl`, no extension), searches keep
 * the plain behaviour. With the switch off the SQL is exactly what it always was.
 */
final class Fold
{
    /** @var array<string,bool> engine => folding available on the current connection */
    private static array $active = [];

    /** True when the environment asks for accent-insensitive search. */
    public static function requested(): bool
    {
        $v = getenv('BRADYPUS_ACCENT_INSENSITIVE');
        return $v !== false && in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Called once per connection by DB\DB: prepares the engine and records whether it can fold. */
    public static function register(\PDO $pdo, string $engine): void
    {
        self::$active[$engine] = false;
        if (!self::requested()) {
            return;
        }
        try {
            if ($engine === 'sqlite' && class_exists(\Normalizer::class)) {
                $pdo->sqliteCreateFunction('bdus_fold', [self::class, 'fold'], 1, \PDO::SQLITE_DETERMINISTIC);
                self::$active[$engine] = true;
            } elseif ($engine === 'pgsql') {
                $has = $pdo->query("SELECT 1 FROM pg_extension WHERE extname = 'unaccent'")->fetchColumn();
                self::$active[$engine] = (bool) $has;
            }
        } catch (\Throwable) {
            self::$active[$engine] = false;
        }
    }

    public static function active(string $engine): bool
    {
        return self::$active[$engine] ?? false;
    }

    /** Text without accents and in lower case (the SQLite `bdus_fold()` function). */
    public static function fold(mixed $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $d = \Normalizer::normalize((string) $s, \Normalizer::FORM_D);
        if ($d === false) {
            $d = (string) $s;
        }
        return mb_strtolower(preg_replace('/\p{Mn}+/u', '', $d) ?? $d, 'UTF-8');
    }

    /** The column expression to compare in a LIKE. */
    public static function col(string $engine, string $expr): string
    {
        if (!self::active($engine)) {
            return $expr;
        }
        return $engine === 'sqlite' ? "bdus_fold({$expr})" : "unaccent({$expr})";
    }

    /** The bound value to compare against (a placeholder, by default). */
    public static function val(string $engine, string $placeholder = '?'): string
    {
        return self::col($engine, $placeholder);
    }
}
