<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Verify;

use DB\DBInterface;
use DB\System\Manage;
use DB\System\Migrate;

/**
 * Read-only data-integrity checks for an app that has just gone through the
 * v4 -> v5 major upgrade (or any migration run).
 *
 * The verifier NEVER writes: every query is a SELECT / PRAGMA. It produces a
 * structured report (a list of checks, each pass|warn|fail|skip) plus a
 * summary. `summary.ok` is false as soon as one check fails.
 *
 * Two families of checks:
 *
 *  - Invariants — run against the live (post-migration) DB alone. They encode
 *    the guarantees the v5 schema must hold: every plugin table linked to
 *    exactly one existing parent, no multi-tenant plugin data left unsplit,
 *    no `<prefix>__` legacy names anywhere, rs ids resolvable, file links
 *    moved out of userlinks, config aligned with the DB, no leftover views.
 *
 *  - Baseline diff — run only when a copy of the pre-upgrade SQLite file is
 *    supplied (`baseline` option). They compare row counts table-by-table so
 *    that data loss (or an incomplete plugin split) shows up as a hard fail,
 *    with the known, legitimate exceptions accounted for (M021/M037 plugin
 *    twins, M030 dropping unresolvable rs rows).
 *
 * SQLite only — the v4 -> v5 major upgrade is itself SQLite only
 * (Upgrade::runMajor / Migrate::runMajor). On any other engine `run()`
 * returns a single "skipped" check.
 */
final class MigrationVerifier
{
    private DBInterface $db;
    private ?DBInterface $baseline;
    /** @var \Config\Config|null */
    private $cfg;
    private ?string $projDir;
    /** @var string[] extra legacy prefixes to look for, e.g. ['paths'] */
    private array $legacyPrefixes;

    /** @var array<int,array<string,mixed>> */
    private array $checks = [];

    /** System table names (v5, always bdus_ prefixed). Filled from Manage. */
    private array $systemTables;

    /**
     * @param array{
     *   baseline?: DBInterface|null,
     *   cfg?: \Config\Config|null,
     *   projDir?: string|null,
     *   legacyPrefixes?: string[]
     * } $opts
     */
    public function __construct(DBInterface $db, array $opts = [])
    {
        $this->db             = $db;
        $this->baseline       = $opts['baseline'] ?? null;
        $this->cfg            = $opts['cfg'] ?? null;
        $this->projDir        = isset($opts['projDir']) ? rtrim((string) $opts['projDir'], '/') . '/' : null;
        $this->legacyPrefixes = array_map(
            fn($p) => rtrim((string) $p, '_') . '__',
            $opts['legacyPrefixes'] ?? []
        );

        try {
            $this->systemTables = (new Manage($db))->available_tables;
        } catch (\Throwable $e) {
            $this->systemTables = [];
        }
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Runs every check and returns the full report:
     *
     *   [
     *     'summary'       => ['passed'=>int,'warnings'=>int,'failed'=>int,
     *                         'skipped'=>int,'total'=>int,'ok'=>bool],
     *     'baseline_used' => bool,
     *     'checks'        => [ ['id'=>…,'status'=>'pass|warn|fail|skip',
     *                           'title'=>…,'detail'=>…,'items'=>[…]], … ],
     *   ]
     */
    public function run(): array
    {
        if ($this->db->getEngine() !== 'sqlite') {
            $this->add('skip', 'engine', 'Verification supports SQLite only',
                'Live database engine is ' . $this->db->getEngine());
            return $this->result();
        }

        $this->checkMigrationsApplied();
        $this->checkPluginRelations();
        $this->checkResidualMultiTenant();
        $this->checkPluginOrphanRows();
        $this->checkPrefixResidue();
        $this->checkRsIntegrity();
        $this->checkFileLinks();
        $this->checkGeodata();
        $this->checkLegacyViews();
        $this->checkConfigDbAlignment();

        if ($this->baseline !== null && $this->baseline->getEngine() === 'sqlite') {
            $this->checkRowCountConservation();
            $this->checkSystemTableConservation();
            $this->checkFileCountOnDisk();
        }

        return $this->result();
    }

    // ── Invariant checks ─────────────────────────────────────────────────────

    private function checkMigrationsApplied(): void
    {
        if (!$this->tableExists($this->db, 'bdus_migrations')) {
            $this->add('fail', 'migrations_applied', 'Migration tracking table missing',
                'bdus_migrations does not exist — the migration run never started');
            return;
        }

        $expected = array_map(
            static fn(string $class) => $class::NAME,
            Migrate::ALL_MIGRATIONS
        );
        $applied = array_column(
            $this->rows($this->db, 'SELECT name FROM bdus_migrations'),
            'name'
        );
        $pending = array_values(array_diff($expected, $applied));

        if ($pending) {
            $this->add('fail', 'migrations_applied',
                count($pending) . ' migration(s) not applied',
                'The upgrade did not run to completion',
                $pending);
        } else {
            $this->add('pass', 'migrations_applied',
                'All ' . count($expected) . ' migrations applied');
        }
    }

    private function checkPluginRelations(): void
    {
        if (!$this->tableExists($this->db, 'bdus_cfg_tables')
            || !$this->tableExists($this->db, 'bdus_cfg_relations')) {
            $this->add('skip', 'plugin_relations', 'Plugin relation check skipped',
                'bdus_cfg_tables / bdus_cfg_relations not available');
            return;
        }

        $plugins = $this->pluginTableNames();
        if (!$plugins) {
            $this->add('pass', 'plugin_relations', 'No plugin tables to check');
            return;
        }

        $bad = [];
        foreach ($plugins as $pt) {
            $rel = $this->rows($this->db,
                "SELECT to_tb FROM bdus_cfg_relations WHERE from_tb = ? AND from_col = 'id_link'",
                [$pt]);

            if (count($rel) === 0) {
                $bad[] = "{$pt}: no parent relation — its rows are invisible in the UI";
                continue;
            }
            if (count($rel) > 1) {
                $bad[] = "{$pt}: " . count($rel) . " parent relations (must be exactly 1)";
                continue;
            }
            $parent = $rel[0]['to_tb'];
            if (!$this->tableExists($this->db, $parent)) {
                $bad[] = "{$pt}: parent table '{$parent}' does not exist";
            }
        }

        if ($bad) {
            $this->add('fail', 'plugin_relations',
                count($bad) . ' of ' . count($plugins) . ' plugin table(s) mis-linked',
                'Each is_plugin=1 table must have exactly one bdus_cfg_relations row (from_col=id_link) pointing at an existing parent',
                $bad);
        } else {
            $this->add('pass', 'plugin_relations',
                count($plugins) . ' plugin table(s) each linked to one existing parent');
        }
    }

    private function checkResidualMultiTenant(): void
    {
        if (!$this->tableExists($this->db, 'bdus_cfg_tables')) {
            $this->add('skip', 'plugin_residual_multitenant', 'Multi-tenant check skipped',
                'bdus_cfg_tables not available');
            return;
        }

        $bad = [];
        $checked = 0;
        foreach ($this->pluginTableNames() as $pt) {
            if ($pt === 'bdus_geodata' || !$this->tableExists($this->db, $pt)) {
                continue;
            }
            // Post-M038 the table_link column is gone from plugin tables — that
            // is the healthy end state, nothing to check.
            if (!in_array('table_link', $this->columns($this->db, $pt), true)) {
                continue;
            }
            $checked++;
            $vals = array_column($this->rows($this->db,
                "SELECT DISTINCT table_link FROM \"{$pt}\"
                  WHERE table_link IS NOT NULL AND table_link != ''"
            ), 'table_link');
            if (count($vals) > 1) {
                $bad[] = "{$pt}: still holds rows for " . count($vals)
                       . ' parents (' . implode(', ', $vals) . ') — M037 split did not complete';
            }
        }

        if ($bad) {
            $this->add('fail', 'plugin_residual_multitenant',
                count($bad) . ' plugin table(s) still multi-tenant', '', $bad);
        } elseif ($checked === 0) {
            $this->add('pass', 'plugin_residual_multitenant',
                'No table_link columns remain on plugin tables (M038 applied)');
        } else {
            $this->add('pass', 'plugin_residual_multitenant',
                "{$checked} plugin table(s) checked — all single-tenant");
        }
    }

    private function checkPluginOrphanRows(): void
    {
        if (!$this->tableExists($this->db, 'bdus_cfg_relations')) {
            return;
        }

        $warn = [];
        $fail = [];
        foreach ($this->pluginTableNames() as $pt) {
            if (!$this->tableExists($this->db, $pt)
                || !in_array('id_link', $this->columns($this->db, $pt), true)) {
                continue;
            }
            $rel = $this->rows($this->db,
                "SELECT to_tb FROM bdus_cfg_relations WHERE from_tb = ? AND from_col = 'id_link'",
                [$pt]);
            if (count($rel) !== 1 || !$this->tableExists($this->db, $rel[0]['to_tb'])) {
                continue; // relation problems already reported by checkPluginRelations
            }
            $parent = $rel[0]['to_tb'];
            $live = (int) $this->scalar($this->db,
                "SELECT COUNT(*) FROM \"{$pt}\" p
                  LEFT JOIN \"{$parent}\" par ON par.id = p.id_link
                  WHERE par.id IS NULL");
            if ($live === 0) {
                continue;
            }

            // If the same table existed in the baseline, only flag orphans the
            // migration itself introduced.
            $base = null;
            if ($this->baseline !== null
                && $this->tableExists($this->baseline, $pt)
                && $this->tableExists($this->baseline, $parent)
                && in_array('id_link', $this->columns($this->baseline, $pt), true)) {
                $base = (int) $this->scalar($this->baseline,
                    "SELECT COUNT(*) FROM \"{$pt}\" p
                      LEFT JOIN \"{$parent}\" par ON par.id = p.id_link
                      WHERE par.id IS NULL");
            }

            if ($base !== null && $live > $base) {
                $fail[] = "{$pt}: {$live} rows with no parent in '{$parent}' ({$base} in v4 — the upgrade created " . ($live - $base) . ')';
            } else {
                $suffix = $base !== null ? " (also {$base} in v4)" : '';
                $warn[] = "{$pt}: {$live} rows with id_link not matching any '{$parent}'{$suffix}";
            }
        }

        if ($fail) {
            $this->add('fail', 'plugin_orphan_rows',
                count($fail) . ' plugin table(s) gained orphan rows', '', array_merge($fail, $warn));
        } elseif ($warn) {
            $this->add('warn', 'plugin_orphan_rows',
                count($warn) . ' plugin table(s) have pre-existing orphan rows',
                'These id_link values had no parent in v4 either — tolerated by the migration, listed for awareness',
                $warn);
        } else {
            $this->add('pass', 'plugin_orphan_rows', 'No orphan plugin rows');
        }
    }

    private function checkPrefixResidue(): void
    {
        $prefixes = $this->legacyPrefixes;
        // Auto-detect: any table still named "<something>__…" is an un-stripped
        // legacy table by itself.
        foreach ($this->allTables($this->db) as $name) {
            if (preg_match('/^([a-z][a-z0-9]*)__/i', $name, $m)) {
                $prefixes[] = $m[1] . '__';
            }
        }
        $prefixes = array_values(array_unique($prefixes));

        if (!$prefixes) {
            $this->add('pass', 'prefix_residue', 'No legacy table prefix detected');
            return;
        }

        $hard = [];
        $soft = [];
        foreach ($prefixes as $p) {
            $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $p) . '%';

            foreach ($this->allTables($this->db) as $name) {
                if (str_starts_with($name, $p)) {
                    $hard[] = "table still named '{$name}'";
                }
            }

            $scan = [
                ['bdus_rs', 'tb'], ['bdus_versions', 'tb'],
                ['bdus_geodata', 'table_link'],
                ['bdus_userlinks', 'tb_one'], ['bdus_userlinks', 'tb_two'],
                ['bdus_cfg_relations', 'from_tb'], ['bdus_cfg_relations', 'to_tb'],
                ['bdus_cfg_tables', 'name'], ['bdus_cfg_tables', 'extra'],
                ['bdus_cfg_fields', 'extra'],
            ];
            foreach ($scan as [$tb, $col]) {
                if (!$this->tableExists($this->db, $tb)
                    || !in_array($col, $this->columns($this->db, $tb), true)) {
                    continue;
                }
                $n = (int) $this->scalar($this->db,
                    "SELECT COUNT(*) FROM \"{$tb}\" WHERE \"{$col}\" LIKE ? ESCAPE '\\'", [$like]);
                if ($n > 0) {
                    $hard[] = "{$tb}.{$col}: {$n} value(s) contain '{$p}'";
                }
            }

            // User-authored SQL — broken by the rename but not a schema fault.
            $softScan = [
                ['bdus_charts', 'sqltext'],
                ['bdus_queries', 'text'], ['bdus_queries', 'tb'],
            ];
            foreach ($softScan as [$tb, $col]) {
                if (!$this->tableExists($this->db, $tb)
                    || !in_array($col, $this->columns($this->db, $tb), true)) {
                    continue;
                }
                $n = (int) $this->scalar($this->db,
                    "SELECT COUNT(*) FROM \"{$tb}\" WHERE \"{$col}\" LIKE ? ESCAPE '\\'", [$like]);
                if ($n > 0) {
                    $soft[] = "{$tb}.{$col}: {$n} row(s) reference '{$p}' — fix the saved SQL by hand";
                }
            }
        }

        if ($hard) {
            $this->add('fail', 'prefix_residue',
                count($hard) . ' legacy-prefix reference(s) left in schema/data',
                'Detected prefixes: ' . implode(', ', $prefixes),
                array_merge($hard, $soft));
        } elseif ($soft) {
            $this->add('warn', 'prefix_residue',
                count($soft) . ' saved SQL statement(s) still reference the old prefix',
                'Detected prefixes: ' . implode(', ', $prefixes), $soft);
        } else {
            $this->add('pass', 'prefix_residue',
                'No residual references to ' . implode(', ', $prefixes));
        }
    }

    private function checkRsIntegrity(): void
    {
        if (!$this->tableExists($this->db, 'bdus_rs')) {
            $this->add('skip', 'rs_integrity', 'Harris Matrix check skipped', 'bdus_rs not present');
            return;
        }

        $problems = [];

        $types = array_column(
            $this->rows($this->db, 'PRAGMA table_info(bdus_rs)'),
            'type', 'name'
        );
        foreach (['first', 'second'] as $col) {
            if (isset($types[$col]) && strtoupper($types[$col]) !== 'INTEGER') {
                $problems[] = "bdus_rs.{$col} is {$types[$col]}, expected INTEGER (M030 not applied)";
            }
        }

        if ($this->tableExists($this->db, 'bdus_rs_v4_backup')) {
            // Not a hard fail — but M030 drops this on a clean run.
            $this->add('warn', 'rs_backup_table',
                'bdus_rs_v4_backup is still present',
                'M030 leaves this table only when the rs id conversion did not complete cleanly — inspect before going live');
        }

        $tbs = array_column(
            $this->rows($this->db, "SELECT DISTINCT tb FROM bdus_rs WHERE tb IS NOT NULL AND tb != ''"),
            'tb'
        );
        foreach ($tbs as $tb) {
            if (!$this->tableExists($this->db, $tb)) {
                $problems[] = "bdus_rs references table '{$tb}' which does not exist";
                continue;
            }
            $unresolved = (int) $this->scalar($this->db,
                "SELECT COUNT(*) FROM bdus_rs r
                  WHERE r.tb = ?
                    AND ( NOT EXISTS (SELECT 1 FROM \"{$tb}\" x WHERE x.id = r.first)
                       OR NOT EXISTS (SELECT 1 FROM \"{$tb}\" x WHERE x.id = r.second) )",
                [$tb]);
            if ($unresolved > 0) {
                $problems[] = "bdus_rs[{$tb}]: {$unresolved} row(s) point at a non-existent record id";
            }
        }

        if ($problems) {
            $this->add('fail', 'rs_integrity',
                count($problems) . ' Harris Matrix integrity problem(s)', '', $problems);
        } else {
            $this->add('pass', 'rs_integrity', 'bdus_rs ids are INTEGER and all resolve to real records');
        }
    }

    private function checkFileLinks(): void
    {
        $fail = [];
        $warn = [];

        if ($this->tableExists($this->db, 'bdus_userlinks')) {
            $stuck = (int) $this->scalar($this->db,
                "SELECT COUNT(*) FROM bdus_userlinks
                  WHERE tb_one IN ('files','bdus_files') OR tb_two IN ('files','bdus_files')");
            if ($stuck > 0) {
                $fail[] = "{$stuck} file link(s) still in bdus_userlinks (M002/M007/M033 did not move them)";
            }
        }

        if ($this->tableExists($this->db, 'bdus_file_links') && $this->tableExists($this->db, 'bdus_files')) {
            $orphanFile = (int) $this->scalar($this->db,
                "SELECT COUNT(*) FROM bdus_file_links fl
                  LEFT JOIN bdus_files f ON f.id = fl.file_id
                  WHERE f.id IS NULL");
            if ($orphanFile > 0) {
                $fail[] = "{$orphanFile} bdus_file_links row(s) point at a missing bdus_files.id";
            }

            $tableNames = array_column($this->rows($this->db,
                "SELECT DISTINCT table_name FROM bdus_file_links WHERE table_name IS NOT NULL AND table_name != ''"
            ), 'table_name');
            foreach ($tableNames as $tn) {
                if (!$this->tableExists($this->db, $tn)) {
                    $warn[] = "bdus_file_links references table '{$tn}' which does not exist";
                    continue;
                }
                $dangling = (int) $this->scalar($this->db,
                    "SELECT COUNT(*) FROM bdus_file_links fl
                      LEFT JOIN \"{$tn}\" r ON r.id = fl.record_id
                      WHERE fl.table_name = ? AND r.id IS NULL", [$tn]);
                if ($dangling > 0) {
                    $warn[] = "bdus_file_links[{$tn}]: {$dangling} link(s) to a missing record";
                }
            }
        }

        if ($fail) {
            $this->add('fail', 'file_links_integrity',
                count($fail) . ' file-attachment problem(s)', '', array_merge($fail, $warn));
        } elseif ($warn) {
            $this->add('warn', 'file_links_integrity',
                count($warn) . ' dangling file link(s)',
                'Links whose record no longer exists — usually leftover from deletions, listed for awareness',
                $warn);
        } else {
            $this->add('pass', 'file_links_integrity', 'File attachments are consistent');
        }
    }

    private function checkGeodata(): void
    {
        if (!$this->tableExists($this->db, 'bdus_geodata')) {
            $this->add('skip', 'geodata_integrity', 'Geodata check skipped', 'bdus_geodata not present');
            return;
        }

        $fail = [];
        $warn = [];
        $links = array_column($this->rows($this->db,
            "SELECT DISTINCT table_link FROM bdus_geodata WHERE table_link IS NOT NULL AND table_link != ''"
        ), 'table_link');

        foreach ($links as $tl) {
            if (!$this->tableExists($this->db, $tl)) {
                $fail[] = "bdus_geodata references table '{$tl}' which does not exist";
                continue;
            }
            $dangling = (int) $this->scalar($this->db,
                "SELECT COUNT(*) FROM bdus_geodata g
                  LEFT JOIN \"{$tl}\" r ON r.id = g.id_link
                  WHERE g.table_link = ? AND r.id IS NULL", [$tl]);
            if ($dangling > 0) {
                $warn[] = "bdus_geodata[{$tl}]: {$dangling} geometry row(s) with no matching record";
            }
        }

        if ($fail) {
            $this->add('fail', 'geodata_integrity',
                count($fail) . ' geodata problem(s)', '', array_merge($fail, $warn));
        } elseif ($warn) {
            $this->add('warn', 'geodata_integrity', count($warn) . ' dangling geometry row(s)', '', $warn);
        } else {
            $this->add('pass', 'geodata_integrity', 'Geodata table_link / id_link all resolve');
        }
    }

    private function checkLegacyViews(): void
    {
        $views = array_column(
            $this->rows($this->db, "SELECT name FROM sqlite_master WHERE type = 'view'"),
            'name'
        );
        if ($views) {
            $this->add('warn', 'legacy_views',
                count($views) . ' SQL view(s) still present',
                'v5 has no user-defined views; Migrate::dropLegacyViews() removes v4 views (and logs their DDL). '
                . 'Recreate any of these against the v5 schema only if an external consumer needs them.',
                $views);
        } else {
            $this->add('pass', 'legacy_views', 'No SQL views present');
        }
    }

    private function checkConfigDbAlignment(): void
    {
        if ($this->cfg === null) {
            $this->add('skip', 'config_db_alignment', 'Config↔DB alignment check skipped',
                'No Config object supplied');
            return;
        }

        $resp = new \DB\Validate\Resp();
        try {
            $sys = new \DB\Validate\SystemTables($resp, $this->db);
            $sys->checkExist();
            $sys->latestStructure();

            $align = new \DB\Validate\DbCfgAlign($resp, $this->db, $this->cfg);
            $align->cfgHasDb();
            $align->cfgColsHasDb();
            $align->cfgHasDb_type();
        } catch (\Throwable $e) {
            $this->add('warn', 'config_db_alignment', 'Config↔DB alignment check could not run',
                $e->getMessage());
            return;
        }

        // Split the "danger" messages: a missing db_type is a v4-import config
        // quality issue (typed search/export degrade, data is intact), whereas
        // a missing column / orphan column / missing table is real schema drift.
        $schema  = [];
        $dbtype  = [];
        foreach ($resp->get() as $m) {
            if (($m['status'] ?? '') !== 'danger') {
                continue;
            }
            $text = $m['text'] ?? '(no text)';
            if (stripos($text, 'db_type') !== false) {
                $dbtype[] = $text;
            } else {
                $schema[] = $text;
            }
        }

        if ($schema) {
            $detail = 'Config field with no column, orphan DB column, or a system table off its model'
                . ($dbtype ? ' (+ ' . count($dbtype) . ' column(s) missing db_type)' : '');
            $this->add('fail', 'config_db_alignment',
                count($schema) . ' config/database schema misalignment(s)', $detail,
                $schema);
        } elseif ($dbtype) {
            $this->add('warn', 'config_db_alignment',
                count($dbtype) . ' column(s) have no db_type',
                'Legacy v4 config — set these in Config › Fields before relying on typed search/export; data itself is unaffected',
                $dbtype);
        } else {
            $this->add('pass', 'config_db_alignment', 'Configuration and database schema are aligned');
        }
    }

    // ── Baseline-diff checks ─────────────────────────────────────────────────

    private function checkRowCountConservation(): void
    {
        $baseTables = $this->userTables($this->baseline);
        $liveTables = $this->userTables($this->db);
        $liveSet    = array_flip($liveTables);

        $issues    = [];
        $split     = [];
        $conserved = 0;

        foreach ($baseTables as $t) {
            $bn = (int) $this->scalar($this->baseline, "SELECT COUNT(*) FROM \"{$t}\"");

            $twinNames = $this->findTwins($t, $liveTables, $baseTables);
            $twinSum   = 0;
            foreach ($twinNames as $tw) {
                $twinSum += (int) $this->scalar($this->db, "SELECT COUNT(*) FROM \"{$tw}\"");
            }

            if (isset($liveSet[$t])) {
                $ln = (int) $this->scalar($this->db, "SELECT COUNT(*) FROM \"{$t}\"");
                if ($ln === $bn) {
                    $conserved++;
                } elseif ($twinNames && $ln + $twinSum === $bn) {
                    $conserved++;
                    $split[] = "{$t}: {$bn} rows kept ({$ln} here + " . implode('/', $twinNames) . ")";
                } elseif ($ln + $twinSum < $bn) {
                    $issues[] = "{$t}: {$bn} rows in v4 → " . ($ln + $twinSum)
                              . ' now (' . ($bn - $ln - $twinSum) . ' missing)';
                } else {
                    $issues[] = "{$t}: {$bn} rows in v4 → " . ($ln + $twinSum)
                              . ' now (' . ($ln + $twinSum - $bn) . ' extra)';
                }
            } else {
                // Table gone: M021's fresh-import split drops the shared table
                // and creates one {t}_{parent} twin per parent.
                if ($twinNames && $twinSum === $bn) {
                    $conserved++;
                    $split[] = "{$t}: dropped, {$bn} rows split into " . implode('/', $twinNames);
                } elseif ($twinNames) {
                    $issues[] = "{$t}: dropped and split into " . implode('/', $twinNames)
                              . " — {$bn} rows in v4 → {$twinSum} now";
                } else {
                    $issues[] = "{$t}: {$bn} rows in v4 → table no longer exists and no split twins found";
                }
            }
        }

        if ($issues) {
            $this->add('fail', 'row_count_conservation',
                count($issues) . ' user table(s) with an unexplained row-count change',
                'Compared against the pre-upgrade SQLite copy',
                array_merge($issues, $split));
        } else {
            $detail = $split ? implode('; ', $split) : '';
            $this->add('pass', 'row_count_conservation',
                "{$conserved} user table(s) conserved their row counts across the upgrade", $detail);
        }
    }

    private function checkSystemTableConservation(): void
    {
        // Tables whose row count must not change during a migration.
        $exact = ['bdus_users', 'bdus_vocabularies', 'bdus_files', 'bdus_user_table_privs'];
        $warn  = [];

        foreach ($exact as $t) {
            if (!$this->tableExists($this->baseline, $t) || !$this->tableExists($this->db, $t)) {
                continue;
            }
            $bn = (int) $this->scalar($this->baseline, "SELECT COUNT(*) FROM \"{$t}\"");
            $ln = (int) $this->scalar($this->db, "SELECT COUNT(*) FROM \"{$t}\"");
            if ($bn !== $ln) {
                $warn[] = "{$t}: {$bn} rows in v4 → {$ln} now";
            }
        }

        // bdus_rs may legitimately shrink (M030 drops unresolvable rows).
        if ($this->tableExists($this->baseline, 'bdus_rs') && $this->tableExists($this->db, 'bdus_rs')) {
            $bn = (int) $this->scalar($this->baseline, 'SELECT COUNT(*) FROM bdus_rs');
            $ln = (int) $this->scalar($this->db, 'SELECT COUNT(*) FROM bdus_rs');
            if ($ln < $bn) {
                $warn[] = "bdus_rs: {$bn} → {$ln} (" . ($bn - $ln)
                        . ' row(s) dropped by M030 as unresolvable — verify they were genuinely orphaned in v4)';
            } elseif ($ln > $bn) {
                $warn[] = "bdus_rs: {$bn} → {$ln} (unexpected growth)";
            }
        }

        if ($warn) {
            $this->add('warn', 'system_table_conservation',
                count($warn) . ' system table(s) changed row count', '', $warn);
        } else {
            $this->add('pass', 'system_table_conservation',
                'Users, vocabularies, files and rs row counts are consistent with v4');
        }
    }

    private function checkFileCountOnDisk(): void
    {
        if ($this->projDir === null) {
            $this->add('skip', 'file_count_on_disk', 'On-disk file count skipped', 'No project directory supplied');
            return;
        }
        $dir = $this->projDir . 'files/';
        if (!is_dir($dir)) {
            $this->add('skip', 'file_count_on_disk', 'On-disk file count skipped', "No {$dir}");
            return;
        }
        if (!$this->tableExists($this->db, 'bdus_files')) {
            $this->add('skip', 'file_count_on_disk', 'On-disk file count skipped', 'bdus_files not present');
            return;
        }

        $onDisk = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $onDisk++;
            }
        }
        $inDb = (int) $this->scalar($this->db, 'SELECT COUNT(*) FROM bdus_files');

        // The files/ tree also holds resized variants, so on-disk >= inDb is
        // normal. Only a shortfall is worth flagging.
        if ($inDb > $onDisk) {
            $this->add('warn', 'file_count_on_disk',
                "bdus_files has {$inDb} rows but only {$onDisk} file(s) on disk",
                "Directory: {$dir}");
        } else {
            $this->add('pass', 'file_count_on_disk',
                "{$inDb} bdus_files row(s), {$onDisk} file(s) under files/");
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function add(string $status, string $id, string $title, string $detail = '', array $items = []): void
    {
        $this->checks[] = [
            'id'     => $id,
            'status' => $status, // pass | warn | fail | skip
            'title'  => $title,
            'detail' => $detail,
            'items'  => array_values($items),
        ];
    }


    private function result(): array
    {
        $count = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
        foreach ($this->checks as $c) {
            $count[$c['status']] = ($count[$c['status']] ?? 0) + 1;
        }

        return [
            'summary' => [
                'passed'   => $count['pass'],
                'warnings' => $count['warn'],
                'failed'   => $count['fail'],
                'skipped'  => $count['skip'],
                'total'    => count($this->checks),
                'ok'       => $count['fail'] === 0,
            ],
            'baseline_used' => $this->baseline !== null,
            'checks'        => $this->checks,
        ];
    }

    /** All is_plugin=1 table names from config. */
    private function pluginTableNames(): array
    {
        if (!$this->tableExists($this->db, 'bdus_cfg_tables')) {
            return [];
        }
        return array_column(
            $this->rows($this->db, 'SELECT name FROM bdus_cfg_tables WHERE is_plugin = 1'),
            'name'
        );
    }

    /** Every non-sqlite_ table name in a DB. */
    private function allTables(DBInterface $db): array
    {
        return array_column($this->rows($db,
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        ), 'name');
    }

    /** Non-system, non-backup user tables. */
    private function userTables(DBInterface $db): array
    {
        return array_values(array_filter($this->allTables($db), function (string $n): bool {
            return $n !== 'bdus_rs_v4_backup'
                && !str_starts_with($n, 'bdus_')
                && !in_array($n, $this->systemTables, true)
                && !in_array($n, ['migrations', 'sqlite_sequence'], true);
        }));
    }

    /**
     * Live tables that look like split twins of $base ("{$base}_{parent}") and
     * did NOT exist under that name in v4.
     *
     * @param string[] $liveTables
     * @param string[] $baseTables
     * @return string[]
     */
    private function findTwins(string $base, array $liveTables, array $baseTables): array
    {
        $baseSet = array_flip($baseTables);
        $out = [];
        foreach ($liveTables as $t) {
            if ($t !== $base
                && str_starts_with($t, $base . '_')
                && !isset($baseSet[$t])
                && preg_match('/^' . preg_quote($base, '/') . '_[a-z0-9_]+$/i', $t)) {
                $out[] = $t;
            }
        }
        sort($out);
        return $out;
    }

    private function scalar(DBInterface $db, string $sql, array $values = [])
    {
        $rows = $db->query($sql, $values, 'read') ?: [];
        if (!$rows) {
            return null;
        }
        $first = $rows[0];
        return is_array($first) ? array_values($first)[0] ?? null : $first;
    }

    private function rows(DBInterface $db, string $sql, array $values = []): array
    {
        return $db->query($sql, $values, 'read') ?: [];
    }

    private function tableExists(DBInterface $db, string $table): bool
    {
        return (int) $this->scalar($db,
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) > 0;
    }

    private function columns(DBInterface $db, string $table): array
    {
        return array_column($this->rows($db, "PRAGMA table_info(\"{$table}\")"), 'name');
    }
}
