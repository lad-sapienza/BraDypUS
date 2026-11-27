<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace File;

use Config\ToDB;
use DB\DBInterface;

/**
 * The configuration of the table `bdus_files` (table row and system fields) in
 * `bdus_cfg_tables` / `bdus_cfg_fields`.
 *
 * Permission rules, locks and the record layer only work on tables that are configured, so a
 * file table without its configuration cannot be governed like the others. New applications get
 * it from CreateApp, existing ones from migration M061; both call this.
 *
 * Idempotent and never destructive: a missing table row or field is added, one that exists (its
 * label or options edited by an administrator) is left alone.
 */
final class ConfigSeed
{
    public const TABLE = 'bdus_files';

    /** System fields: they are part of the table and cannot be removed from the configuration. */
    public const FIELDS = [
        ['name' => 'id',          'label' => 'ID',          'type' => 'text',      'db_type' => 'INTEGER', 'readonly' => true],
        ['name' => 'uuid',        'label' => 'UUID',        'type' => 'text',      'db_type' => 'TEXT',    'readonly' => true],
        ['name' => 'creator',     'label' => 'Creator',     'type' => 'text',      'db_type' => 'INTEGER', 'readonly' => true],
        ['name' => 'filename',    'label' => 'Filename',    'type' => 'text',      'db_type' => 'TEXT',    'readonly' => true, 'check' => ['not_empty']],
        ['name' => 'ext',         'label' => 'Extension',   'type' => 'text',      'db_type' => 'TEXT',    'readonly' => true, 'check' => ['not_empty']],
        ['name' => 'keywords',    'label' => 'Keywords',    'type' => 'text',      'db_type' => 'TEXT'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'long_text', 'db_type' => 'TEXT'],
        ['name' => 'printable',   'label' => 'Printable',   'type' => 'boolean',   'db_type' => 'INTEGER'],
        ['name' => 'attribution', 'label' => 'Attribution', 'type' => 'text',      'db_type' => 'TEXT'],
        ['name' => 'public',      'label' => 'Public',      'type' => 'boolean',   'db_type' => 'INTEGER'],
    ];

    /** Adds what is missing. Requires `bdus_cfg_tables` and `bdus_cfg_fields` to exist. */
    public static function ensure(DBInterface $db): void
    {
        if (!$db->query('SELECT id FROM bdus_cfg_tables WHERE name = ?', [self::TABLE], 'read')) {
            ToDB::upsertTable($db, [
                'name'     => self::TABLE,
                'label'    => 'Files',
                'order'    => 'id',
                'id_field' => 'id',
                'preview'  => ['id', 'filename', 'ext', 'keywords'],
            ]);
        }
        foreach (self::FIELDS as $fld) {
            $exists = $db->query(
                'SELECT id FROM bdus_cfg_fields WHERE table_name = ? AND name = ?',
                [self::TABLE, $fld['name']],
                'read'
            );
            if (!$exists) {
                ToDB::upsertField($db, self::TABLE, $fld);
            }
        }
    }
}
