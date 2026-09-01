<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds an optional label column to bdus_userlinks for typed manual links.
 *
 * The label stores a free-text relation type (e.g. "is part of", "cites")
 * chosen by the user when creating the link. NULL means untyped (legacy links).
 */
class M029_AddLabelToUserlinks
{
    public const NAME = 'M029_add_label_to_userlinks';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_userlinks')) {
            return;
        }

        if (!$manage->columnExists('bdus_userlinks', 'label')) {
            $manage->getDb()->query(
                'ALTER TABLE bdus_userlinks ADD COLUMN label TEXT',
                [],
                'boolean'
            );
        }
    }
}
