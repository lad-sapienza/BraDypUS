<?php

declare(strict_types=1);

namespace Bdus;

/**
 * Minimal DBML parser covering the subset used by BraDypUS.
 *
 * Supported constructs:
 *   Table name { col type [constraints] ... Note: '...' }
 *   Enum name { value ... }
 *   Inline column constraints: pk, increment, not null, note: '...', ref: > table.col
 *
 * Unsupported (silently ignored): TableGroup, Project, multi-line notes (''' '''),
 * standalone Ref: blocks, column default/unique constraints.
 *
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */
class DbmlParser
{
    /**
     * Parses a DBML string and returns a structured array.
     *
     * @return array{
     *   tables: array<string, array{
     *     note: string,
     *     columns: array<string, array{name:string,type:string,pk:bool,increment:bool,not_null:bool,note:string,ref:array{type:string,table:string,col:string}|null}>,
     *     column_order: list<string>
     *   }>,
     *   enums: array<string, array{values:list<string>,note:string}>
     * }
     */
    public function parse(string $dbml): array
    {
        $result = ['tables' => [], 'enums' => []];
        $state  = 'top';
        $currentTable = null;
        $currentEnum  = null;

        foreach (preg_split('/\r?\n/', $dbml) as $rawLine) {
            $trimmedRaw = trim($rawLine);

            // Note: is not valid DBML inside Enum blocks; the bdus:vocabulary
            // marker is emitted as a comment line instead.
            if ($state === 'enum' && preg_match('/^\/\/\s*bdus:vocabulary\b/', $trimmedRaw)) {
                $result['enums'][$currentEnum]['note'] = 'bdus:vocabulary';
                continue;
            }

            $line = $this->stripLineComment($trimmedRaw);
            if ($line === '') {
                continue;
            }

            switch ($state) {
                case 'top':
                    if (preg_match('/^[Tt]able\s+["\'`]?(\w+)["\'`]?\s*[\{]?/', $line, $m)) {
                        $currentTable = $m[1];
                        $result['tables'][$currentTable] = [
                            'note'         => '',
                            'columns'      => [],
                            'column_order' => [],
                        ];
                        $state = 'table';
                    } elseif (preg_match('/^[Ee]num\s+["\'`]?(\w+)["\'`]?\s*\{/', $line, $m)) {
                        $currentEnum = $m[1];
                        $result['enums'][$currentEnum] = ['values' => [], 'note' => ''];
                        $state = 'enum';
                    }
                    break;

                case 'table':
                    if ($line === '}') {
                        $state = 'top';
                        $currentTable = null;
                    } elseif (preg_match('/^[Nn]ote:\s*[\'"](.+?)[\'"]\s*$/', $line, $m)) {
                        $result['tables'][$currentTable]['note'] = $m[1];
                    } else {
                        $col = $this->parseColumn($line);
                        if ($col !== null) {
                            $result['tables'][$currentTable]['columns'][$col['name']] = $col;
                            $result['tables'][$currentTable]['column_order'][] = $col['name'];
                        }
                    }
                    break;

                case 'enum':
                    if ($line === '}') {
                        $state = 'top';
                        $currentEnum = null;
                    } elseif (preg_match('/^[Nn]ote:\s*[\'"](.+?)[\'"]\s*$/', $line, $m)) {
                        $result['enums'][$currentEnum]['note'] = $m[1];
                    } else {
                        // enum value: strip inline [constraints] and surrounding quotes
                        $value = trim((string) preg_replace('/\s*\[.*\]\s*$/', '', $line));
                        $value = trim($value, '"\'`');
                        if ($value !== '') {
                            $result['enums'][$currentEnum]['values'][] = $value;
                        }
                    }
                    break;
            }
        }

        return $result;
    }

    /** Strips // comments that appear outside quoted strings. */
    private function stripLineComment(string $line): string
    {
        $out      = '';
        $inSingle = false;
        $inDouble = false;

        for ($i = 0, $len = strlen($line); $i < $len; $i++) {
            $c = $line[$i];
            if ($c === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($c === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble && $c === '/' && ($line[$i + 1] ?? '') === '/') {
                break;
            }
            $out .= $c;
        }

        return trim($out);
    }

    /**
     * Parses a single column definition line.
     * Returns null if the line does not look like a column definition.
     *
     * @return array{name:string,type:string,pk:bool,increment:bool,not_null:bool,note:string,ref:array|null}|null
     */
    private function parseColumn(string $line): ?array
    {
        // col_name  type_name[(len)]  [optional inline constraints]
        if (!preg_match('/^(\w+)\s+([\w]+(?:\(\d+\))?)(.*)?$/', $line, $m)) {
            return null;
        }

        $col = [
            'name'      => $m[1],
            'type'      => strtolower((string) preg_replace('/\(\d+\)$/', '', $m[2])),
            'pk'        => false,
            'increment' => false,
            'not_null'  => false,
            'note'      => '',
            'ref'       => null,
        ];

        $rest = trim($m[3] ?? '');
        if ($rest !== '' && $rest[0] === '[') {
            $constraints    = $this->parseInlineConstraints($rest);
            $col['pk']        = $constraints['pk'];
            $col['increment'] = $constraints['increment'];
            $col['not_null']  = $constraints['not_null'];
            $col['note']      = $constraints['note'];
            $col['ref']       = $constraints['ref'];
        }

        return $col;
    }

    /**
     * Parses the inline constraint block "[pk, not null, note: '...', ref: > tb.col]".
     *
     * @return array{pk:bool,increment:bool,not_null:bool,note:string,ref:array{type:string,table:string,col:string}|null}
     */
    private function parseInlineConstraints(string $block): array
    {
        $closingPos = strrpos($block, ']');
        $inner      = $closingPos !== false
            ? substr($block, 1, $closingPos - 1)
            : substr($block, 1);

        $result = [
            'pk'        => false,
            'increment' => false,
            'not_null'  => false,
            'note'      => '',
            'ref'       => null,
        ];

        foreach ($this->splitRespectingQuotes($inner) as $part) {
            $part  = trim($part);
            $lower = strtolower($part);

            if (preg_match('/^note:\s*[\'"](.+?)[\'"]\s*$/i', $part, $m)) {
                $result['note'] = $m[1];
            } elseif (preg_match('/^ref:\s*([<>\-])\s*(\w+)\.(\w+)/i', $part, $m)) {
                $result['ref'] = ['type' => $m[1], 'table' => $m[2], 'col' => $m[3]];
            } elseif ($lower === 'pk' || $lower === 'primary key') {
                $result['pk'] = true;
            } elseif (in_array($lower, ['increment', 'auto_increment', 'autoincrement'], true)) {
                $result['increment'] = true;
            } elseif ($lower === 'not null') {
                $result['not_null'] = true;
            }
        }

        return $result;
    }

    /**
     * Splits a comma-separated string while respecting single- and double-quoted regions.
     *
     * @return list<string>
     */
    private function splitRespectingQuotes(string $str): array
    {
        $parts     = [];
        $current   = '';
        $inQuote   = false;
        $quoteChar = '';

        for ($i = 0, $len = strlen($str); $i < $len; $i++) {
            $c = $str[$i];
            if (!$inQuote && ($c === "'" || $c === '"')) {
                $inQuote   = true;
                $quoteChar = $c;
                $current  .= $c;
            } elseif ($inQuote && $c === $quoteChar) {
                $inQuote  = false;
                $current .= $c;
            } elseif (!$inQuote && $c === ',') {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $c;
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
