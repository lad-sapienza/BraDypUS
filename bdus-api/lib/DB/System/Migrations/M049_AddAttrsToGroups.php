<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Group variables and server-enforced values (#51). Adds:
 *  - `bdus_groups.attrs`: a JSON object of group attributes
 *    ({"site": "Ostia"} or {"site": ["Ostia", "Cuma"]}) read by the access rules
 *    through `$GROUP.<key>`;
 *  - `bdus_group_rules.enforce`: a JSON object `{field: value|variable|list}` on
 *    create/update rules: the values the server accepts (or fills in) for those
 *    fields. See Auth\Access.
 *
 * Purely additive: NULL means "no attributes" / "nothing enforced".
 */
class M049_AddAttrsToGroups
{
    public const NAME = 'M049_add_attrs_to_groups';

    public static function run(Manage $manage): void
    {
        if ($manage->tableExists('bdus_groups') && !$manage->columnExists('bdus_groups', 'attrs')) {
            $manage->getDb()->exec("ALTER TABLE bdus_groups ADD COLUMN attrs TEXT");
        }
        if ($manage->tableExists('bdus_group_rules') && !$manage->columnExists('bdus_group_rules', 'enforce')) {
            $manage->getDb()->exec("ALTER TABLE bdus_group_rules ADD COLUMN enforce TEXT");
        }
    }
}
