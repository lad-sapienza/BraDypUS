<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Alter;

use DB\DBInterface;

class Sqlite implements AlterInterface
{
    private string $sqlite_version;
    private DBInterface $db;

    public function __construct(DBInterface $db)
    {
        $v = \SQLite3::version();
        $this->sqlite_version = $v['versionString'];
        $this->db = $db;
    }

    public function renameTable(string $old, string $new): bool
    {
        return $this->db->execInTransaction("ALTER TABLE \"{$old}\" RENAME TO \"{$new}\"");
    }

    public function renameFld(string $tb, string $old, string $new, $fld_type = false): bool
    {
        if (\version_compare($this->sqlite_version, '3.25.0') >= 0) {
            return $this->db->execInTransaction("ALTER TABLE \"{$tb}\" RENAME COLUMN \"{$old}\" TO \"{$new}\"");
        }

        // Pre-3.25.0: table recreation.
        $res = $this->db->query('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $tb]);
        if (!$res || empty($res[0]['sql'])) {
            throw new \Exception("Cannot get CREATE SQL for table $tb");
        }
        $origSql = $res[0]['sql'];

        $pragma = $this->db->query("PRAGMA table_info(\"{$tb}\")", [], 'read');
        if (!$pragma) throw new \Exception("Cannot get fields for $tb");

        $srcCols = [];
        $dstCols = [];
        foreach ($pragma as $row) {
            $srcCols[] = '"' . $row['name'] . '"';
            $dstCols[] = '"' . ($row['name'] === $old ? $new : $row['name']) . '"';
        }

        $tmpTb  = uniqid($tb . '_');
        $newDdl = preg_replace(
            '/CREATE\s+TABLE\s+(?:"[^"]*"|[^\s(]+)/i',
            'CREATE TABLE "' . $tmpTb . '"',
            $origSql,
            1
        );
        $newDdl = preg_replace(
            '/([(,]\s*)"?' . preg_quote($old, '/') . '"?(?=\s)/im',
            '$1"' . $new . '"',
            $newDdl
        );

        // $dstCols has the renamed column; $srcCols has the original name for SELECT.
        return $this->executeRecreation($tb, $tmpTb, $newDdl, $dstCols, $srcCols);
    }

    public function addFld(string $tb, string $fld_name, string $fld_type): bool
    {
        return $this->db->execInTransaction("ALTER TABLE \"{$tb}\" ADD COLUMN \"{$fld_name}\" {$fld_type}");
    }

    public function dropFld(string $tb, string $fld_name): bool
    {
        $res = $this->db->query('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $tb]);
        if (!$res || empty($res[0]['sql'])) {
            throw new \Exception("Cannot get CREATE SQL for $tb");
        }
        $origSql = $res[0]['sql'];

        $pragma = $this->db->query("PRAGMA table_info(\"{$tb}\")", [], 'read');
        if (!$pragma) throw new \Exception("Cannot get fields for $tb");

        $fields = [];
        foreach ($pragma as $row) {
            if ($row['name'] !== $fld_name) $fields[] = '"' . $row['name'] . '"';
        }

        $tmpTb  = uniqid($tb . '_');
        $newDdl = preg_replace(
            [
                '/CREATE\s+TABLE\s+(?:"[^"]*"|[^\s(]+)/i',
                '/"?\b' . preg_quote($fld_name, '/') . '\b"?[^\),]+,?/im',
                '/,\s*\)/im',
            ],
            [
                'CREATE TABLE "' . $tmpTb . '"',
                '',
                ')',
            ],
            $origSql,
        );

        return $this->executeRecreation($tb, $tmpTb, $newDdl, $fields);
    }

    public function createMinimalTable(string $tb, bool $is_plugin, string $pluginOf = ''): bool
    {
        if ($is_plugin) {
            $fkClause = ($pluginOf !== '')
                ? ", FOREIGN KEY (id_link) REFERENCES \"{$pluginOf}\"(id) ON DELETE RESTRICT ON UPDATE CASCADE"
                : '';
            $sql = "CREATE TABLE IF NOT EXISTS \"{$tb}\" ("
                . "id INTEGER PRIMARY KEY AUTOINCREMENT, "
                . "table_link TEXT NOT NULL, "
                . "id_link INTEGER NOT NULL"
                . $fkClause
                . ")";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS \"{$tb}\" ("
                . "id INTEGER PRIMARY KEY AUTOINCREMENT, "
                . "creator INTEGER NOT NULL"
                . ")";
        }
        return $this->db->execInTransaction($sql);
    }

    public function dropTable(string $tb): bool
    {
        return $this->db->execInTransaction("DROP TABLE IF EXISTS \"{$tb}\"");
    }

    // ── FK management ─────────────────────────────────────────────────────────

    public function addForeignKey(
        string $tb,
        string $col,
        string $refTable,
        string $refCol,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'CASCADE'
    ): bool {
        if ($this->hasForeignKey($tb, $col)) {
            return true;
        }

        $res = $this->db->query('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $tb]);
        if (!$res || empty($res[0]['sql'])) {
            throw new \Exception("Cannot get CREATE SQL for $tb");
        }
        $origSql = $res[0]['sql'];

        $pragma   = $this->db->query("PRAGMA table_info(\"{$tb}\")", [], 'read') ?: [];
        $colNames = array_map(fn($r) => '"' . $r['name'] . '"', $pragma);

        $tmpTb    = uniqid($tb . '_');
        $fkClause = ", FOREIGN KEY (\"{$col}\") REFERENCES \"{$refTable}\"(\"{$refCol}\")"
                  . " ON DELETE " . strtoupper($onDelete)
                  . " ON UPDATE " . strtoupper($onUpdate);

        // Insert FK clause before the final closing paren; rename CREATE TABLE
        // using a general pattern that matches whatever name is in the DDL —
        // after a prior recreation, sqlite_master may still hold the old tmp name.
        $newDdl = $origSql;
        $newDdl = preg_replace('/\)\s*$/', $fkClause . ')', trim($newDdl));
        $newDdl = preg_replace(
            '/CREATE\s+TABLE\s+(?:"[^"]*"|[^\s(]+)/i',
            'CREATE TABLE "' . $tmpTb . '"',
            $newDdl,
            1
        );

        return $this->executeRecreation($tb, $tmpTb, $newDdl, $colNames);
    }

    public function dropForeignKey(string $tb, string $col): bool
    {
        if (!$this->hasForeignKey($tb, $col)) {
            return true;
        }

        $res = $this->db->query('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $tb]);
        if (!$res || empty($res[0]['sql'])) {
            throw new \Exception("Cannot get CREATE SQL for $tb");
        }
        $origSql = $res[0]['sql'];

        $pragma   = $this->db->query("PRAGMA table_info(\"{$tb}\")", [], 'read') ?: [];
        $colNames = array_map(fn($r) => '"' . $r['name'] . '"', $pragma);

        $tmpTb  = uniqid($tb . '_');
        // Remove the FK clause for this column. The pattern must tolerate the
        // parentheses inside REFERENCES ref_table(ref_col), so we cannot use
        // [^,)]+ — instead we match: FOREIGN KEY (col) REFERENCES tbl(col) extras.
        $newDdl = preg_replace(
            '/,?\s*FOREIGN KEY\s*\([^)]*"?' . preg_quote($col, '/') . '"?[^)]*\)\s*REFERENCES\s*[^(]+\([^)]+\)[^,)]*/i',
            '',
            $origSql
        );
        $newDdl = preg_replace(
            '/CREATE\s+TABLE\s+(?:"[^"]*"|[^\s(]+)/i',
            'CREATE TABLE "' . $tmpTb . '"',
            $newDdl,
            1
        );

        return $this->executeRecreation($tb, $tmpTb, $newDdl, $colNames);
    }

    public function hasForeignKey(string $tb, string $col): bool
    {
        $fks = $this->db->query("PRAGMA foreign_key_list(\"{$tb}\")", [], 'read') ?: [];
        foreach ($fks as $fk) {
            if ($fk['from'] === $col) return true;
        }
        return false;
    }

    public function checkOrphans(string $tb, string $col, string $refTable, string $refCol): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM \"{$tb}\" "
            . "WHERE \"{$col}\" IS NOT NULL "
            . "AND \"{$col}\" NOT IN (SELECT \"{$refCol}\" FROM \"{$refTable}\")",
            [],
            'read'
        );
        return (int)($result[0]['cnt'] ?? 0);
    }

    // ── Index management ──────────────────────────────────────────────────────

    public function createIndex(string $tb, string $name, array $columns, bool $unique = false): bool
    {
        $uniq = $unique ? 'UNIQUE ' : '';
        $cols = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
        $sql  = "CREATE {$uniq}INDEX IF NOT EXISTS \"{$name}\" ON \"{$tb}\" ({$cols})";
        return $this->db->exec($sql);
    }

    public function dropIndex(string $tb, string $name): bool
    {
        return $this->db->exec("DROP INDEX IF EXISTS \"{$name}\"");
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Shared table-recreation helper.
     * Turns off FK enforcement for the connection, runs the recreation inside a
     * transaction, then re-enables FK enforcement regardless of outcome.
     *
     * @param string   $tb       Original table name
     * @param string   $tmpTb    Temporary table name (already embedded in $newDdl)
     * @param string   $newDdl   The CREATE TABLE … statement for the temp table
     * @param string[] $colNames Quoted column names used in INSERT … SELECT
     */
    /**
     * @param string[]      $dstColNames  Quoted column names for the INSERT target
     * @param string[]|null $srcColNames  Quoted column names for the SELECT source;
     *                                   if null, mirrors $dstColNames (used by addFk/dropFk/dropFld)
     */
    private function executeRecreation(
        string $tb,
        string $tmpTb,
        string $newDdl,
        array  $dstColNames,
        ?array $srcColNames = null
    ): bool {
        $srcColNames ??= $dstColNames;
        $dstCols = implode(', ', $dstColNames);
        $srcCols = implode(', ', $srcColNames);

        // FK enforcement must be off while the table is being rebuilt.
        $this->db->exec('PRAGMA foreign_keys = OFF');

        try {
            $this->db->beginTransaction();

            $stmts = [
                $newDdl,
                "INSERT INTO \"{$tmpTb}\" ({$dstCols}) SELECT {$srcCols} FROM \"{$tb}\"",
                "DROP TABLE \"{$tb}\"",
                "ALTER TABLE \"{$tmpTb}\" RENAME TO \"{$tb}\"",
            ];

            foreach ($stmts as $s) {
                if ($this->db->exec($s) === false) {
                    throw new \Exception("Error executing: $s");
                }
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $th) {
            $this->db->rollBack();
            return false;
        } finally {
            $this->db->exec('PRAGMA foreign_keys = ON');
        }
    }
}
