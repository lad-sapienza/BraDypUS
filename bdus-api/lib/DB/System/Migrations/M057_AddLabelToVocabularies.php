<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * Adds `label` to bdus_vocabularies (#80): an optional extended name shown instead of the
 * stored value (`def`), e.g. value `CER`, label "Ceramica". NULL (every existing entry)
 * means "show the value", exactly as before.
 *
 * Purely additive.
 */
class M057_AddLabelToVocabularies
{
    public const NAME = 'M057_add_label_to_vocabularies';

    public static function run(Manage $manage): void
    {
        if (!$manage->tableExists('bdus_vocabularies')) {
            return;
        }
        if (!$manage->columnExists('bdus_vocabularies', 'label')) {
            $manage->getDb()->exec('ALTER TABLE bdus_vocabularies ADD COLUMN label TEXT');
        }
    }
}
