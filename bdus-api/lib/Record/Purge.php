<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use Config\Config;
use DB\DBInterface;

/**
 * Retention: delete or anonymise the records of a table that are older than N
 * days, together with their version history.
 *
 * Deleting a record keeps a full snapshot of it in bdus_versions (that is what
 * makes it restorable), and every later edit adds another one: personal data
 * would outlive the record. This class removes the snapshots with the record,
 * and, for an anonymisation, those of the rows it blanks (they hold the old values).
 *
 * "Older than" is measured on a date field of the table you name: records carry
 * no creation date of their own. Only main tables, never plugin tables (those
 * go with their host record). Files attached to a record are unlinked, not
 * deleted from disk.
 *
 * Used by bin/purge-records.php. A dry run (the default) counts and writes nothing.
 */
final class Purge
{
    /** Fields that are never blanked: structure, not content. */
    private const KEEP = ['id', 'creator'];

    /**
     * @param  string[] $anonymize fields to blank; empty = delete the records
     * @return array{table:string, action:string, cutoff:string, matched:int, versions:int, applied:bool}
     * @throws \InvalidArgumentException on a table, field or age that is not usable
     */
    public static function run(
        DBInterface $db,
        Config $cfg,
        string $tb,
        string $dateField,
        int $olderThanDays,
        array $anonymize = [],
        bool $apply = false,
        ?int $now = null
    ): array {
        if (!in_array($tb, $cfg->get('tables.*.name') ?: [], true) || $cfg->get("tables.{$tb}.is_plugin") || $cfg->get("tables.{$tb}.is_view")) {
            throw new \InvalidArgumentException("'{$tb}' is not a main table of this application");
        }
        if ($olderThanDays < 1) {
            throw new \InvalidArgumentException('--older-than must be a number of days, 1 or more');
        }

        $fields = [];
        foreach ($cfg->get("tables.{$tb}.fields") ?: [] as $f) {
            $fields[$f['name']] = $f;
        }
        if (!isset($fields[$dateField]) || ($fields[$dateField]['type'] ?? '') !== 'date') {
            throw new \InvalidArgumentException("'{$dateField}' is not a date field of '{$tb}'");
        }
        foreach ($anonymize as $a) {
            if (!isset($fields[$a]) || in_array($a, self::KEEP, true) || $a === $dateField) {
                throw new \InvalidArgumentException("'{$a}' cannot be anonymised in '{$tb}'");
            }
        }

        $cutoff = date('Y-m-d', ($now ?? time()) - $olderThanDays * 86400);
        $where  = "{$dateField} IS NOT NULL AND {$dateField} <> '' AND {$dateField} < ?";
        $params = [$cutoff];
        if ($anonymize) {
            // rows already blank are not touched again: a second run changes nothing
            $where .= ' AND (' . implode(' OR ', array_map(fn($a) => "({$a} IS NOT NULL AND {$a} <> '')", $anonymize)) . ')';
        }

        $ids = array_map('intval', array_column($db->query("SELECT id FROM {$tb} WHERE {$where}", $params, 'read') ?: [], 'id'));

        $plugins   = array_values(array_filter($cfg->get("tables.{$tb}.plugin") ?: [], fn($p) => in_array($p, $cfg->get('tables.*.name') ?: [], true)));
        $pluginIds = [];
        foreach ($plugins as $p) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $pluginIds[$p] = array_merge($pluginIds[$p] ?? [], array_map('intval', array_column(
                    $db->query("SELECT id FROM {$p} WHERE id_link IN ({$in})", $chunk, 'read') ?: [], 'id'
                )));
            }
        }

        $versions = 0;
        $count    = function (string $tbl, array $rowIds) use ($db): int {
            $n = 0;
            foreach (array_chunk($rowIds, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $n += (int) ($db->query("SELECT COUNT(*) AS c FROM bdus_versions WHERE tb = ? AND rowid IN ({$in})", array_merge([$tbl], $chunk), 'read')[0]['c'] ?? 0);
            }
            return $n;
        };
        if ($ids) {
            $versions = $count($tb, $ids);
            if (!$anonymize) {
                foreach ($pluginIds as $p => $rowIds) {
                    $versions += $count($p, $rowIds);
                }
            }
        }

        $result = [
            'table'    => $tb,
            'action'   => $anonymize ? 'anonymize' : 'delete',
            'cutoff'   => $cutoff,
            'matched'  => count($ids),
            'versions' => $versions,
            'applied'  => false,
        ];
        if (!$apply || !$ids) {
            return $result;
        }

        $db->beginTransaction();
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));

                if ($anonymize) {
                    $set = implode(', ', array_map(fn($a) => "{$a} = NULL", $anonymize));
                    $db->query("UPDATE {$tb} SET {$set} WHERE id IN ({$in})", $chunk, 'boolean');
                } else {
                    $db->query("DELETE FROM bdus_file_links WHERE table_name = ? AND record_id IN ({$in})", array_merge([$tb], $chunk), 'boolean');
                    $db->query("DELETE FROM bdus_userlinks WHERE (tb_one = ? AND id_one IN ({$in})) OR (tb_two = ? AND id_two IN ({$in}))", array_merge([$tb], $chunk, [$tb], $chunk), 'boolean');
                    $db->query("DELETE FROM bdus_rs WHERE tb = ? AND (first IN ({$in}) OR second IN ({$in}))", array_merge([$tb], $chunk, $chunk), 'boolean');
                    $db->query("DELETE FROM bdus_geodata WHERE table_link = ? AND id_link IN ({$in})", array_merge([$tb], $chunk), 'boolean');
                    foreach ($plugins as $p) {
                        $db->query("DELETE FROM {$p} WHERE id_link IN ({$in})", $chunk, 'boolean');
                    }
                    $db->query("DELETE FROM {$tb} WHERE id IN ({$in})", $chunk, 'boolean');
                }
                $db->query("DELETE FROM bdus_versions WHERE tb = ? AND rowid IN ({$in})", array_merge([$tb], $chunk), 'boolean');
            }
            if (!$anonymize) {
                foreach ($pluginIds as $p => $rowIds) {
                    foreach (array_chunk($rowIds, 500) as $chunk) {
                        $in = implode(',', array_fill(0, count($chunk), '?'));
                        $db->query("DELETE FROM bdus_versions WHERE tb = ? AND rowid IN ({$in})", array_merge([$p], $chunk), 'boolean');
                    }
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $result['applied'] = true;
        return $result;
    }
}
