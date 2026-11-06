<?php

namespace DB;

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Counts the statements in an SQL string (used by the Run SQL module).
 *
 * It is a scanner, not a parser: it only needs to know where a statement ends.
 * A ";" does NOT end a statement when it is inside
 *   - a string ('…'), a quoted identifier ("…", `…`, […]) or a comment (-- …, /* … *​/);
 *   - a dollar-quoted body ($$ … $$, $tag$ … $tag$: PostgreSQL functions);
 *   - the BEGIN … END body of a CREATE TRIGGER (SQLite, MySQL/MariaDB).
 *
 * When in doubt it errs towards "one statement": the engine then decides. The only goal
 * is to catch what SQLite would otherwise silently drop.
 */
class SqlStatements
{
    /** Closers that must not decrement the BEGIN/CASE depth of a trigger body (END IF; END LOOP; …). */
    private const END_QUALIFIERS = ['IF', 'LOOP', 'WHILE', 'REPEAT'];

    public static function count(string $sql): int
    {
        $len        = strlen($sql);
        $count      = 0;
        $hasContent = false;
        $words      = [];     // words of the current statement (first ones only are kept)
        $isTrigger  = false;
        $depth      = 0;      // BEGIN/CASE nesting inside a trigger body
        $pendingEnd = false;  // last word was END: look at the next one
        $i          = 0;

        $onWord = function (string $w) use (&$words, &$isTrigger, &$depth, &$pendingEnd): void {
            $w = strtoupper($w);

            if ($pendingEnd) {
                $pendingEnd = false;
                if ($w === 'CASE') {
                    $depth--;       // END CASE closes the CASE opened earlier
                    return;
                }
                if (in_array($w, self::END_QUALIFIERS, true)) {
                    return;         // END IF / END LOOP …: not a block closer
                }
                $depth--;           // plain END closes a BEGIN / CASE
            }

            if (count($words) < 8) {
                $words[] = $w;
                if (!$isTrigger && ($words[0] ?? '') === 'CREATE' && $w === 'TRIGGER') {
                    $isTrigger = true;
                }
            }
            if (!$isTrigger) {
                return;
            }
            if ($w === 'BEGIN' || $w === 'CASE') {
                $depth++;
            } elseif ($w === 'END') {
                $pendingEnd = true; // decided by the next word
            }
        };

        while ($i < $len) {
            $c = $sql[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }

            // comments
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                $nl = strpos($sql, "\n", $i);
                $i  = $nl === false ? $len : $nl + 1;
                continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i   = $end === false ? $len : $end + 2;
                continue;
            }

            if ($c === ';') {
                if ($pendingEnd) {
                    $pendingEnd = false;
                    $depth      = max(0, $depth - 1);
                }
                if ($depth > 0) {
                    $i++;
                    continue;
                }
                if ($hasContent) {
                    $count++;
                }
                $hasContent = false;
                $words      = [];
                $isTrigger  = false;
                $i++;
                continue;
            }

            $hasContent = true;

            // strings and quoted identifiers ('' / "" inside are two adjacent literals: harmless)
            if ($c === "'" || $c === '"' || $c === '`' || $c === '[') {
                $close = $c === '[' ? ']' : $c;
                $end   = strpos($sql, $close, $i + 1);
                $i     = $end === false ? $len : $end + 1;
                continue;
            }

            // dollar quoting
            if ($c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i)) {
                $end = strpos($sql, $m[0], $i + strlen($m[0]));
                $i   = $end === false ? $len : $end + strlen($m[0]);
                continue;
            }

            if (ctype_alpha($c) || $c === '_') {
                $j = $i + 1;
                while ($j < $len && (ctype_alnum($sql[$j]) || $sql[$j] === '_')) {
                    $j++;
                }
                $onWord(substr($sql, $i, $j - $i));
                $i = $j;
                continue;
            }

            $i++;
        }

        if ($hasContent) {
            $count++;
        }
        return $count;
    }
}
