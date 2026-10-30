<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Config;

use DB\Alter;
use DB\DB;
use DB\Inspect;

/**
 * Activates the fuzzy-date plugin on a table: the five chrono_* columns, their
 * (hidden) field definitions and the `fuzzy_date` flag.
 *
 * Shared by `Config::activateFuzzyDate()` (Config → Tables) and `DbmlImporter`
 * (`bdus:fuzzy_date=1`), so both end up with exactly the same table.
 */
class FuzzyDate
{
    /** Columns added to the core table, with their DB type. */
    public const COLUMNS = [
        'chrono_from'      => 'INTEGER',
        'chrono_to'        => 'INTEGER',
        'chrono_label'     => 'VARCHAR(200)',
        'chrono_certainty' => 'INTEGER',
        'chrono_period'    => 'VARCHAR(200)',
    ];

    public static function activate(DB $db, Config $cfg, string $tb): void
    {
        $inspect = new Inspect($db);
        $alter   = new Alter($db);

        // Only add columns when the physical table already exists in the DB.
        // The seed may call activateFuzzyDate before the DB table is created
        // (config-only mode); in that case we skip schema changes but still
        // update the config flag below.
        if ($inspect->tableExists($tb)) {
            $existingNames = array_column($inspect->tableColumns($tb), 'fld');
            foreach (self::COLUMNS as $col => $type) {
                if (!in_array($col, $existingNames, true)) {
                    $alter->addFld($tb, $col, $type);
                }
            }
        }

        $tbData = $cfg->get("tables.$tb") ?: [];
        $tbData['name']       = $tb;
        $tbData['fuzzy_date'] = true;
        // Drop 'link' so setTable does not call upsertRelations — this method
        // only updates the fuzzy_date flag and must never touch FK relations.
        unset($tbData['link']);
        $cfg->setTable($tbData);

        // Write field definitions for the chrono columns so they appear in the
        // config field list and can be managed from the UI (hide=true: the
        // ChronoSection handles display, not the regular FieldEditor).
        $chronoFieldDefs = [
            'chrono_from'      => ['name' => 'chrono_from',      'label' => 'Cronologia da',   'type' => 'text', 'hide' => true],
            'chrono_to'        => ['name' => 'chrono_to',        'label' => 'Cronologia a',     'type' => 'text', 'hide' => true],
            'chrono_label'     => ['name' => 'chrono_label',     'label' => 'Etichetta crono',  'type' => 'text', 'hide' => true],
            'chrono_certainty' => ['name' => 'chrono_certainty', 'label' => 'Certezza crono',   'type' => 'text', 'hide' => true, 'db_type' => 'INTEGER'],
            'chrono_period'    => ['name' => 'chrono_period',    'label' => 'Periodo crono',    'type' => 'text', 'hide' => true],
        ];
        $existingFields = array_keys($cfg->get("tables.$tb.fields") ?: []);
        foreach ($chronoFieldDefs as $fldName => $fldDef) {
            if (!in_array($fldName, $existingFields, true)) {
                $cfg->setFld($tb, $fldName, $fldDef);
            }
        }
    }
}
