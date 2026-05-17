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

        $raw = $this->cfg->get('tables.*.label', 'is_plugin', null);
        $tables = [];
        foreach ($raw as $name => $label) {
            $tables[] = [
                'name'     => $name,
                'label'    => $label ?: $name,
                'rs_field' => $this->cfg->get("tables.{$name}.rs") ?? null,
            ];
        }

        $this->returnJson(['tables' => $tables]);
    }
}