<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Validate;

use DB\DBInterface;
use DB\Validate\Resp;

class DbCfgAlign
{
    private $db;
    private $resp;
    private $inspect;
    private $cfg;

    public function __construct(Resp $resp, DBInterface $db, \Config\Config $cfg)
    {
        $this->resp = $resp;
        $this->db = $db;
        $this->cfg = $cfg;

        $db_engine = $db->getEngine();
        $this->inspect = new \DB\Inspect($this->db);
    }

    public function cfgHasDb_type(): void
    {
        $cfg_tbs = $this->cfg->get('tables.*.name');
        foreach ($cfg_tbs as $cfg_tb) {
            // System tables (bdus_*, e.g. the geodata plugin) are seeded by BraDypUS,
            // not configured by the user: nothing to fix on their side.
            if (str_starts_with($cfg_tb, 'bdus_')) {
                continue;
            }
            $db_types = $this->cfg->get("tables.$cfg_tb.fields.*.db_type") ?: [];
            $fuzzy    = !empty($this->cfg->get("tables.$cfg_tb.fuzzy_date"));
            foreach ($db_types as $fld => $type) {
                // chrono_* columns are added by the fuzzy date feature, not by the user.
                if ($fuzzy && array_key_exists($fld, \Config\FuzzyDate::COLUMNS)) {
                    continue;
                }
                if ($type && !empty($type)){
                    $this->resp->set(
                        'success',
                        "Column {$cfg_tb}.{$fld} has db_type: $type"
                    );
                } else {
                    $this->resp->set(
                        'danger',
                        "Missing db_type for column {$cfg_tb}.{$fld}"
                    );
                }
            }
        }
    }

    /**
     * Every table needs an `id` (column and configured field); every data table
     * (not a plugin, and for a view only when it can be written) also needs a
     * `creator`, which the permission checks (self writer) rely on.
     */
    public function cfgCoreFields(): void
    {
        foreach ($this->cfg->get('tables.*') ?: [] as $tb => $tbData) {
            if (!$this->inspect->tableExists($tb)) {
                continue;                                  // reported by cfgHasDb()
            }
            $dbCols  = array_column($this->inspect->tableColumns($tb), 'fld');
            $cfgCols = array_keys($tbData['fields'] ?? []);

            $needed = ['id'];
            $isPlugin = !empty($tbData['is_plugin']);
            $isView   = !empty($tbData['is_view']);
            $writable = ($tbData['view_writable'] ?? 'none') !== 'none';
            if (!$isPlugin && (!$isView || $writable)) {
                $needed[] = 'creator';
            }

            foreach ($needed as $col) {
                $inDb  = in_array($col, $dbCols, true);
                $inCfg = in_array($col, $cfgCols, true);
                if ($inDb && $inCfg) {
                    $this->resp->set('success', "Table {$tb} has the required field {$col}");
                    continue;
                }
                if (!$inDb) {
                    $this->resp->set(
                        'danger',
                        "Table {$tb} has no {$col} column in the database",
                        $isView
                            ? "Add {$col} to the SQL view {$tb}, then activate it again from Database objects"
                            : "Add the column {$tb}.{$col} to the database"
                    );
                }
                if (!$inCfg) {
                    $this->resp->set(
                        'danger',
                        "Table {$tb} has no {$col} field in the configuration",
                        "Add the field {$col} to {$tb} (read-only, INTEGER)"
                    );
                }
            }
        }
    }

    public function cfgHasDb(): void
    {
        $cfg_tbs = $this->cfg->get('tables.*.name');

        foreach ($cfg_tbs as $cfg_tb) {
            if ($this->inspect->tableExists($cfg_tb)){
                $this->resp->set(
                    'success',
                    "Configuration table $cfg_tb exists in database"
                );
            } else {
                $this->resp->set(
                    'danger',
                    "Configuration table $cfg_tb does not exist in database"
                );
            }
        }
    }

    /**
     * Tables vs views: what the configuration says (`is_view`) must match what the
     * database has, a view must keep its `id` column, and the way a view can be
     * written (`view_writable`, probed when it was activated) may have changed.
     */
    public function cfgViewFlags(): void
    {
        $objects = null;
        foreach ($this->cfg->get('tables.*') ?: [] as $tb => $tbData) {
            if (!$this->inspect->tableExists($tb)) {
                continue;                                  // reported by cfgHasDb()
            }
            $cfgView    = (bool) ($tbData['is_view'] ?? false);
            $actualView = $this->inspect->isView($tb);

            if ($cfgView && !$actualView) {
                $this->resp->set('danger', "{$tb} is configured as a SQL view but is a table in the database",
                    "Remove {$tb} from the configuration and activate it again from Database objects");
                continue;
            }
            if (!$cfgView && $actualView) {
                $this->resp->set('danger', "{$tb} is a SQL view in the database but is configured as a table",
                    "Remove {$tb} from the configuration and activate it again from Database objects, so it is treated as a view");
                continue;
            }
            if (!$cfgView) {
                continue;
            }

            if ($objects === null) {
                $objects = array_column($this->inspect->listObjects(), null, 'name');
            }
            $o = $objects[$tb] ?? null;
            if ($o === null) {
                continue;
            }
            if (!$o['has_id']) {
                $this->resp->set('danger', "SQL view {$tb} has no id column any more",
                    "BraDypUS needs an id column in every table and view: add it back to the view, or remove the view from the configuration");
            } else {
                $this->resp->set('success', "SQL view {$tb} has an id column");
            }
            $saved = $tbData['view_writable'] ?? 'none';
            if ($o['view_writable'] !== $saved) {
                $this->resp->set('warning',
                    "SQL view {$tb}: writable as '{$o['view_writable']}' in the database, configured as '{$saved}'",
                    "Open the view's settings and press 'Re-check how it can be written'");
            }
        }
    }

    public function cfgColsHasDb()
    {
        $cfg = $this->cfg->get('tables.*');

        foreach ($cfg as $tb => $tb_data) {
            // A configured table that is not in the database is reported by
            // cfgHasDb(); asking for its columns would throw and abort the whole
            // report instead of showing it.
            if (!$this->inspect->tableExists($tb)) {
                continue;
            }

            $cfg_cols = array_keys($tb_data['fields'] ?? []);
            $db_cols = array_map(function($el){
                return $el['fld'];
            }, $this->inspect->tableColumns($tb));

            $isView = !empty($tb_data['is_view']);
            $this->resp->set('head', "Checking $tb from configuration to database");

            foreach ($cfg_cols as $col) {
                if (!in_array($col, $db_cols)){
                    $this->resp->set(
                        'danger',
                        "Configuration field {$tb}.{$col} is not available in database table",
                        $isView
                            ? "The SQL view {$tb} no longer has this column: restore it in the view, or remove the view from the configuration and activate it again"
                            : "Add column {$tb}.{$col} to the database, or remove it from the configuration"
                    );
                } else {
                    $this->resp->set(
                        'success',
                        "Configuration field {$tb}.{$col} is available in database table"
                    );
                }
            }


            $this->resp->set('head', "Checking $tb from database to configuration");

            foreach ($db_cols as $col) {
                if (!in_array($col, array_values($cfg_cols))){
                    $this->resp->set(
                        'danger',
                        "Database column {$tb}.{$col} is not in the configuration",
                        $isView
                            ? "The SQL view {$tb} has a new column: remove the view from the configuration and activate it again to pick it up"
                            : "Add {$tb}.{$col} to the configuration, or drop the column from the database"
                    );
                } else {
                    $this->resp->set(
                        'success',
                        "Database column {$tb}.{$col} is available in the configuration files"
                    );
                }
            }
            
        }

    }
}