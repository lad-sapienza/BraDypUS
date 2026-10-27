<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Auth;

/**
 * Privilege / access-control helpers.
 * Extracted from the legacy utils class.
 */
class Authorization
{
    /** Numeric privilege thresholds (lower = more privileged). */
    public const SUPERADM = 1;
    public const ADM      = 10;
    public const UPDATE   = 20;
    public const CREATE   = 25;
    public const READ     = 30;
    public const ENTER    = 39;

    /** Maps privilege codes to their string keys. */
    private const PRIVILEGE_MAP = [
        1  => 'super_admin',
        10 => 'admin',
        20 => 'writer',
        25 => 'self_writer',
        30 => 'reader',
        40 => 'waiting',
    ];

    /** Application status for this request: 'on' | 'frozen' | 'off'. */
    private static string $appStatus = 'on';

    /** Supplies the current principal's Access (group rules and locks); set by the Dispatcher. */
    private static ?\Closure $accessProvider = null;

    /** Maps the privilege strings of can() to the actions of Access. */
    private const ACCESS_ACTION = [
        'read'          => 'read',
        'preview'       => 'read',
        'add_new'       => 'create',
        'edit'          => 'update',
        'delete'        => 'delete',
        'multiple_edit' => 'update',
    ];

    /**
     * Sets the application status used by can(). Called by the Dispatcher once
     * the app config is loaded (status lives in bdus_cfg_app since v5.1; it used
     * to be read from cfg/app_data.json, which no longer exists). Unknown or
     * empty values fall back to 'on'.
     */
    public static function setAppStatus(?string $status): void
    {
        self::$appStatus = in_array($status, ['on', 'frozen', 'off'], true) ? $status : 'on';
    }

    /**
     * Registers the provider of the current principal's Access, used by can()
     * when a table is given. Without a provider only the global tier counts.
     */
    public static function setAccessProvider(?\Closure $provider): void
    {
        self::$accessProvider = $provider;
    }

    /**
     * Checks whether the current user has at least the given privilege level.
     *
     * With a $table the answer is "may the user do this on at least some rows of
     * that table": the global tier decides first, and group rules (see Access) can
     * grant more. Row-level restrictions are applied where the queries are built.
     *
     * @param string      $privilege One of: enter|read|preview|add_new|edit|multiple_edit|admin|super_admin
     * @param int|null    $creator   Record creator id — enables self_writer to edit their own records
     * @param string|null $table     Table the action is about (enables group rules)
     */
    public static function can(string $privilege = 'edit', ?int $creator = null, ?string $table = null): bool
    {
        if (self::canByTier($privilege, $creator)) {
            return true;
        }

        return $table !== null && self::canByRules($privilege, $table);
    }

    /**
     * What the current user may do on $table, as the interface needs it:
     * [create, read, update, delete], each true when they may do it on at least
     * some rows (row-level limits are applied by the queries themselves). A self
     * writer counts as able to update and delete: they may on their own records.
     *
     * @return array{create: bool, read: bool, update: bool, delete: bool}
     */
    public static function tableAbilities(string $table): array
    {
        $uid = CurrentUser::id();
        return [
            'create' => self::can('add_new', null, $table),
            'read'   => self::can('read', null, $table),
            'update' => self::can('edit', $uid, $table),
            'delete' => self::can('delete', $uid, $table),
        ];
    }

    /**
     * Like can(), for endpoints that span several tables and filter them one by
     * one: true when the tier or a group rule grants $privilege on at least one table.
     */
    public static function canOnSomeTable(string $privilege): bool
    {
        return self::canByTier($privilege, null) || self::canByRules($privilege, null);
    }

    /** Group-rule part of can(); $table = null means "any table". */
    private static function canByRules(string $privilege, ?string $table): bool
    {
        $action = self::ACCESS_ACTION[$privilege] ?? null;
        if ($action === null || self::$accessProvider === null) {
            return false;
        }

        $user_priv = CurrentUser::privilege();
        if (!$user_priv) {
            return false;
        }
        // Same application-status rules as the tier path.
        if (self::$appStatus === 'off' && $user_priv > 1) {
            return false;
        }
        if ($action !== 'read' && self::$appStatus === 'frozen') {
            return false;
        }

        $access = (self::$accessProvider)();
        return $table === null
            ? $access->canAnyTable($action)
            : $access->can($action, $table, false);
    }

    private static function canByTier(string $privilege, ?int $creator): bool
    {
        $app_status = self::$appStatus;

        $user_priv = CurrentUser::privilege();

        if (!$user_priv || $user_priv === 0) {
            return false;
        }

        // 'off' locks everyone out except super admins (login is still allowed to super admins).
        $locked = $app_status === 'off' && $user_priv > 1;

        return match ($privilege) {
            'enter'                 => !$locked && $user_priv <= self::ENTER,
            'read', 'preview'       => !$locked && $user_priv < 31,
            'add_new'               => $app_status !== 'frozen' && $user_priv < 26,
            // 'delete' has the tier thresholds of 'edit' (a self writer deletes their own records);
            // they differ only for group rules, which grant 'delete' separately.
            'edit', 'delete'        => $app_status !== 'frozen' && (
                                           $user_priv < 21 ||
                                           ($creator && $creator == CurrentUser::id() && $user_priv < 26)
                                       ),
            'multiple_edit'         => $app_status !== 'frozen' && $user_priv < 21,
            'admin'                 => $app_status !== 'frozen' && $user_priv < 11,
            'super_admin'           => $user_priv < 2,
            default                 => false,
        };
    }

    /**
     * Translates between privilege codes (int) and string keys.
     *
     * - false / no arg  → string key for the current user
     * - 'all'           → full [int => string] map
     * - string key      → int code
     * - int code        → string key
     */
    public static function privilege(mixed $input = false): mixed
    {
        if (!$input) {
            $input = CurrentUser::privilege();
        }

        if ($input === 'all') {
            return self::PRIVILEGE_MAP;
        }

        if (in_array($input, self::PRIVILEGE_MAP, true)) {
            return array_search($input, self::PRIVILEGE_MAP);
        }

        if (array_key_exists($input, self::PRIVILEGE_MAP)) {
            return self::PRIVILEGE_MAP[$input];
        }

        return false;
    }
}
