<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since 4.0.0
 * @uses pref
 * @uses $_SESSION
 * @uses cfg
 * @uses utils
 * 
 */

use DB\System\Migrate;

class home_ctrl extends Controller
{
    /**
     * Returns JSON list of non-plugin tables available to the current user.
     *
     * GET ?obj=home_ctrl&method=listTables
     * Response: { tables: [ { name: string, label: string }, ... ] }
     */
    public function listTables(): void
    {
        if (!\Auth\Authorization::can('enter')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $raw = $this->cfg->get('tables.*.label', 'is_plugin', null) ?: [];
        $tables = [];
        foreach ($raw as $name => $label) {
            // Built-in system tables (bdus_files, bdus_geodata, …) are stored in
            // bdus_cfg_tables for validation purposes but must not appear to users.
            if (str_starts_with($name, 'bdus_')) continue;
            $rs = $this->cfg->get("tables.{$name}.rs");
            $tables[] = [
                'name'     => $name,
                'label'    => $label ?: $name,
                'rs_field' => ($rs && $rs !== false) ? $rs : null,
            ];
        }

        $this->returnJson(['tables' => $tables]);
    }

    /**
     * Returns the list of all known DB migrations and their applied status.
     * Admin-only: useful for diagnosing upgrade state across multiple apps.
     *
     * GET /api/migrations
     *
     * Response: {
     *   status: 'success',
     *   total: N,
     *   applied: N,
     *   migrations: [
     *     { name: string, applied: bool, applied_at: string|null },
     *     …
     *   ]
     * }
     */
    public function getMigrations(): void
    {
        if (!\Auth\Authorization::can('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        // Fetch already-applied migrations from DB.
        $rows = $this->db->query(
            "SELECT name, applied_at FROM bdus_migrations ORDER BY applied_at ASC",
            [],
            'read'
        ) ?: [];

        // Build lookup: name → applied_at timestamp
        $appliedMap = [];
        foreach ($rows as $row) {
            $appliedMap[$row['name']] = $row['applied_at'];
        }

        // Merge with the full ordered list of known migrations.
        $migrations = [];
        foreach (Migrate::ALL_MIGRATIONS as $class) {
            $name      = $class::NAME;
            $appliedAt = $appliedMap[$name] ?? null;
            $migrations[] = [
                'name'       => $name,
                'applied'    => isset($appliedMap[$name]),
                'applied_at' => $appliedAt
                    ? date('Y-m-d H:i:s', (int)$appliedAt)
                    : null,
            ];
        }

        $applied = count(array_filter($migrations, fn($m) => $m['applied']));

        $this->returnJson([
            'status'     => 'success',
            'total'      => count($migrations),
            'applied'    => $applied,
            'migrations' => $migrations,
        ]);
    }
}