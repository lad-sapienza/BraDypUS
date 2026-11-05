<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Config;

use DB\DB;
use DB\Inspect;

/**
 * Registers an object that already exists in the database — a table that is
 * not in the configuration, or a SQL view — as a BraDypUS table.
 *
 * Activation only writes configuration (bdus_cfg_tables / _fields / _relations);
 * it never issues DDL, so the table or view is left exactly as it is.
 *
 * Rules (see PLAN-viste-e-tabelle-orfane.md):
 *  - the object must have an `id` column;
 *  - a view that can be written must also have a `creator` column;
 *  - views are flagged `is_view` (+ `view_writable`) in the table's `extra`,
 *    so Config::rejectIfView() and the UI can tell them from real tables.
 */
class Activate
{
    /** SQL type → [BraDypUS field type, db_type], same spirit as DbmlImporter::TYPE_MAP. */
    public static function mapType(string $sqlType): array
    {
        $t = strtolower(trim(preg_replace('/\(.*$/', '', $sqlType)));

        if (str_contains($t, 'bool')) {
            return ['type' => 'boolean', 'db_type' => 'INTEGER'];
        }
        if (str_contains($t, 'timestamp') || str_contains($t, 'datetime')) {
            return ['type' => 'date', 'db_type' => 'TIMESTAMP'];
        }
        if ($t === 'date') {
            return ['type' => 'date', 'db_type' => 'TEXT'];
        }
        // Plain TEXT is what SQLite (and many PostgreSQL schemas) use for every
        // string, short or not: it maps to a one-line `text` field. Only the
        // explicitly large types become a text area; the user can change it later.
        if ($t === 'mediumtext' || $t === 'longtext' || str_contains($t, 'clob')) {
            return ['type' => 'long_text', 'db_type' => 'TEXT'];
        }
        if (str_contains($t, 'int') || str_contains($t, 'serial')) {
            return ['type' => 'text', 'db_type' => 'INTEGER'];
        }
        return ['type' => 'text', 'db_type' => 'TEXT'];
    }

    /**
     * Why $object (an entry of Inspect::listObjects()) cannot be activated, or
     * null when it can. $configured = names of the tables already in the config.
     */
    public static function reason(array $object, array $configured): ?string
    {
        if (in_array($object['name'], $configured, true)) {
            return 'already_configured';
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $object['name'])) {
            return 'invalid_name';
        }
        if (!$object['has_id']) {
            return 'missing_id_column';
        }
        if ($object['kind'] === 'view' && $object['view_writable'] !== 'none' && !$object['has_creator']) {
            return 'missing_creator_column';
        }
        return null;
    }

    /**
     * Dry run: what activating $name would create, and whether it can be done.
     *
     * @return array<string, mixed>  `['status'=>'error','code'=>…]` when the
     *   object cannot even be described (not_found); otherwise a description
     *   with `activatable` and, when false, `reason` (the error code activate() answers).
     */
    public static function analyse(DB $db, Config $cfg, string $name): array
    {
        if ($name === '' || str_starts_with($name, 'bdus_')) {
            return ['status' => 'error', 'code' => 'not_found'];
        }

        $inspect = new Inspect($db);
        $object  = null;
        foreach ($inspect->listObjects() as $o) {
            if ($o['name'] === $name) {
                $object = $o;
                break;
            }
        }
        if ($object === null) {
            return ['status' => 'error', 'code' => 'not_found'];
        }

        $isView = $object['kind'] === 'view';

        // ── Proposed fields ──────────────────────────────────────────────
        $fields = [];
        foreach ($object['columns'] as $c) {
            $m = self::mapType($c['type']);
            $f = [
                'name'    => $c['fld'],
                'label'   => $c['fld'],
                'type'    => $m['type'],
                'db_type' => $m['db_type'],
            ];
            if ($c['fld'] === 'id') {
                $f = ['name' => 'id', 'label' => 'Id', 'type' => 'text', 'readonly' => true, 'db_type' => 'INTEGER'];
            } elseif ($c['fld'] === 'creator') {
                $f = ['name' => 'creator', 'label' => 'Creator', 'type' => 'text', 'readonly' => true, 'db_type' => 'INTEGER'];
            }
            $fields[] = $f;
        }

        // ── Foreign keys the database already knows (tables only) ───────
        $configured = $cfg->get('tables.*.name') ?: [];
        $relations  = [];
        if (!$isView) {
            foreach ($inspect->foreignKeys($name) as $fk) {
                $onDel = in_array($fk['on_delete'], Relations::POLICIES, true) ? $fk['on_delete'] : 'NO ACTION';
                $onUpd = in_array($fk['on_update'], Relations::POLICIES, true) ? $fk['on_update'] : 'NO ACTION';
                $self  = $fk['to_tb'] === $name;
                $relations[] = [
                    'from_col'  => $fk['from_col'],
                    'to_tb'     => $fk['to_tb'],
                    'to_col'    => $fk['to_col'],
                    'on_delete' => $self ? 'RESTRICT' : $onDel,
                    'on_update' => $self ? 'CASCADE'  : $onUpd,
                    // A relation to a table BraDypUS does not manage cannot be
                    // recorded; it is reported so the user can activate that
                    // table first and re-run.
                    'configured' => $self || in_array($fk['to_tb'], $configured, true),
                ];
            }
        }

        $inactive = in_array($name, array_keys(LoadFromDB::inactiveTables($db)), true);
        $reason   = self::reason($object, $configured);

        return [
            'status'      => 'success',
            'name'        => $name,
            'kind'        => $object['kind'],
            'sql'         => $object['sql'],
            'view_writable' => $object['view_writable'],
            'has_id'      => $object['has_id'],
            'has_creator' => $object['has_creator'],
            'fields'      => $fields,
            'relations'   => $relations,
            'activatable' => $reason === null,
            'reason'      => $reason,
            // Deactivated earlier: activating switches the saved configuration back on.
            'inactive'    => $inactive,
        ];
    }

    /**
     * Writes the configuration for $name. Never touches the table/view itself.
     *
     * @return array<string, mixed>  `status` success|error, `code`, and on
     *   success `kind`, `fields` (count), `relations_created`, `relations_skipped`.
     */
    public static function activate(DB $db, Config $cfg, string $name): array
    {
        $plan = self::analyse($db, $cfg, $name);
        if ($plan['status'] !== 'success') {
            return $plan;
        }
        if (!$plan['activatable']) {
            return ['status' => 'error', 'code' => $plan['reason']];
        }

        // A table that was deactivated keeps its whole configuration: switch it back on.
        if ($plan['inactive']) {
            ToDB::setActive($db, $name, true);
            return ['status' => 'success', 'code' => 'table_reactivated', 'tb' => $name, 'kind' => $plan['kind']];
        }

        $isView = $plan['kind'] === 'view';

        // Preview: id + the first free-text field, so lists are readable at once.
        $preview = ['id'];
        foreach ($plan['fields'] as $f) {
            if ($f['name'] !== 'id' && $f['name'] !== 'creator' && in_array($f['type'], ['text', 'long_text'], true)
                && $f['db_type'] === 'TEXT') {
                $preview[] = $f['name'];
                break;
            }
        }

        $tbData = [
            'name'      => $name,
            'label'     => $name,
            'order'     => 'id',
            'id_field'  => 'id',
            'preview'   => $preview,
            'is_plugin' => 0,
        ];
        if ($isView) {
            $tbData['is_view']       = true;
            $tbData['view_writable'] = $plan['view_writable'];
        }

        $created = 0;
        $skipped = [];

        $db->beginTransaction();
        try {
            $cfg->setTable($tbData);
            foreach ($plan['fields'] as $f) {
                $cfg->setFld($name, $f['name'], $f);
            }

            // Record only the config row: the FK already exists in the database.
            foreach ($plan['relations'] as $r) {
                if (!$r['configured']) {
                    $skipped[] = ['from_col' => $r['from_col'], 'to_tb' => $r['to_tb'], 'reason' => 'target_not_configured'];
                    continue;
                }
                if ((bool) $cfg->get("tables.{$r['to_tb']}.is_plugin")) {
                    $skipped[] = ['from_col' => $r['from_col'], 'to_tb' => $r['to_tb'], 'reason' => 'plugin_relation_not_allowed'];
                    continue;
                }
                $dup = $db->query(
                    'SELECT id FROM bdus_cfg_relations WHERE from_tb=? AND from_col=?',
                    [$name, $r['from_col']], 'read'
                );
                if (!empty($dup)) {
                    continue;
                }
                $db->query(
                    'INSERT INTO bdus_cfg_relations (from_tb, from_col, to_tb, to_col, on_delete, on_update)
                     VALUES (?,?,?,?,?,?)',
                    [$name, $r['from_col'], $r['to_tb'], $r['to_col'], $r['on_delete'], $r['on_update']],
                    'id'
                );
                $created++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return [
            'status'            => 'success',
            'code'              => 'db_object_activated',
            'tb'                => $name,
            'kind'              => $plan['kind'],
            'fields'            => count($plan['fields']),
            'relations_created' => $created,
            'relations_skipped' => $skipped,
        ];
    }
}
