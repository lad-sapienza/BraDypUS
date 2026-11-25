<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\System\Migrations;

use DB\System\Manage;

/**
 * One-time repair for PostgreSQL applications whose id sequences fell behind their tables.
 *
 * Rows loaded with explicit ids (the Directus migration's bdus_files, imports, restores of a
 * partial dump) do not advance the sequence, so the first ordinary INSERT — e.g. a file upload —
 * fails with "duplicate key value violates unique constraint ..._pkey". Every serial column
 * whose sequence is behind MAX(id) is moved up to it.
 *
 * A no-op on SQLite and MySQL, and on a PostgreSQL database where nothing is behind.
 */
class M059_RealignPostgresSequences
{
    public const NAME = 'M059_realign_postgres_sequences';

    public static function run(Manage $manage): void
    {
        $manage->realignSequences();
    }
}
