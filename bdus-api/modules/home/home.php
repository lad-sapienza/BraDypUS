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
        if (!\utils::canUser('enter')) {
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
}