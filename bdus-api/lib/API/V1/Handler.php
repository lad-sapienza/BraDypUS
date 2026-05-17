<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Handles all v1 REST API endpoint logic after routing and authentication.
 */

namespace API\V1;

use DB\DBInterface;
use Config\Config;
use DB\System\Manage;
use Record\Read;
use SQL\ShortSql\ParseShortSql;

class Handler
{
    public function __construct(
        private DBInterface $db,
        private Config      $cfg,
        private string      $prefix,
        private string      $app
    ) {}

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/{app}
     * Lists all user-facing (non-system, non-plugin) tables.
     */
    public function listTables(): void
    {
        $systemNames = $this->systemTableNames();
        $all         = $this->cfg->get('tables') ?? [];
        $tables      = [];

        foreach ($all as $name => $t) {
            $stripped = str_replace($this->prefix, '', $name);
            if (in_array($stripped, $systemNames, true)) { continue; }
            if ($t['is_plugin'] ?? false)                { continue; }
            $tables[] = [
                'name'  => $stripped,
                'label' => $t['label'] ?? $name,
            ];
        }

        Router::respond($tables);
    }

    /**
     * GET /api/v1/{app}/schema[/{table}]
     * Returns full configuration for one table or all tables.
     */
    public function schema(?string $table): void
    {
        // Prefix the table name when a specific table is requested
        $tb = $table ? $this->prefix . $table : null;
        $inspect = \API\Inspect::Configuration($this->cfg, $tb);
        Router::respond($inspect);
    }

    /**
     * GET /api/v1/{app}/vocabularies/{name}
     * Returns vocabulary items for the given vocabulary name.
     */
    public function vocabulary(string $name): void
    {
        $manage = new Manage($this->db, $this->prefix);
        $rows   = $manage->getBySQL('vocabularies', 'voc = ? ORDER BY sort ASC LIMIT 500 OFFSET 0', [$name]);
        $data   = array_column($rows ?: [], 'def');
        Router::respond($data);
    }

    /**
     * GET /api/v1/{app}/{table}
     * Lists records with optional filtering, sorting, pagination.
     *
     * Query parameters:
     *   page      (int, default 1)
     *   limit     (int, default 30, max 200)
     *   sort      Directus convention: "field,-other" → "ASC / DESC"
     *   fields    Comma-separated field list, or * for all
     *   shortsql  ShortSQL string (highest priority; overrides filter/simple params)
     *   filter    Directus-style JSON filter (second priority)
     *   search    Full-text LIKE on preview fields (third priority)
     *   {field}=  Simple equality on any config field (lowest priority)
     */
    public function listRecords(string $table): void
    {
        if (!$this->assertUserTable($table)) { return; }

        // ShortSQL path: delegate entirely to the existing Search infrastructure.
        // ParseShortSql returns a full SELECT; we use a COUNT subquery for total,
        // and add LIMIT/OFFSET via QueryObject before fetching data.
        if (!empty($_GET['shortsql'])) {
            $this->listRecordsViaShortSql($_GET['shortsql'], $table);
            return;
        }

        // ── Pagination ────────────────────────────────────────────────────────
        $limit  = min((int)($_GET['limit'] ?? 30), 200);
        $limit  = max(1, $limit);
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        // ── WHERE clause ──────────────────────────────────────────────────────
        [$where, $values] = $this->buildWhere($table);

        // ── Count ─────────────────────────────────────────────────────────────
        $countSql = "SELECT COUNT(*) AS tot FROM $table"
                  . ($where ? " WHERE $where" : '');
        $total = (int)($this->db->query($countSql, $values, 'read')[0]['tot'] ?? 0);

        // ── Sort ──────────────────────────────────────────────────────────────
        $sortSql = $this->parseSort($_GET['sort'] ?? null, $table);

        // ── Field selection ───────────────────────────────────────────────────
        $fields = $this->parseFields($_GET['fields'] ?? '*', $table);

        // ── Data query ────────────────────────────────────────────────────────
        $sql = "SELECT $fields FROM $table"
             . ($where ? " WHERE $where" : '')
             . " ORDER BY " . ($sortSql ?: "$table.id ASC")
             . " LIMIT $limit OFFSET $offset";

        $data = $this->db->query($sql, $values, 'read') ?: [];

        Router::respond($data, [
            'total_count'  => $total,
            'filter_count' => $total,
            'page'         => $page,
            'limit'        => $limit,
            'total_pages'  => (int)ceil($total / $limit),
        ]);
    }

    /**
     * GET /api/v1/{app}/{table}/{id}
     * Returns a single full record (core + plugins + links + files + geodata).
     */
    public function singleRecord(string $table, string $id): void
    {
        if (!is_numeric($id)) {
            Router::error('Record id must be numeric', 'INVALID_ID', 400);
            return;
        }
        if (!$this->assertUserTable($table)) { return; }

        try {
            $record = new Read((int)$id, null, $table, $this->db, $this->cfg);
            $data   = $record->getFull();
            if (empty($data['core'])) {
                Router::error('Record not found', 'NOT_FOUND', 404);
                return;
            }
            Router::respond($data);
        } catch (\Throwable $e) {
            Router::error($e->getMessage(), 'RECORD_ERROR', 500);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * ShortSQL path for listRecords.
     *
     * ParseShortSql returns a full SELECT statement via QueryObject::getSql().
     * Strategy:
     *   1. Parse to get the QueryObject.
     *   2. Use a COUNT(*) subquery for the total.
     *   3. Set LIMIT/OFFSET on the QueryObject, re-generate SQL for data.
     */
    private function listRecordsViaShortSql(string $shortSql, string $table): void
    {
        $limit  = min((int)($_GET['limit'] ?? 30), 200);
        $limit  = max(1, $limit);
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        try {
            $parser = new ParseShortSql($this->prefix, $this->cfg);
            $qo     = $parser->parseAll($shortSql)->getQueryObject();

            // Total count (no LIMIT)
            [$baseSql, $baseValues] = $qo->getSql();
            $countSql = 'SELECT COUNT(*) AS tot FROM (' . $baseSql . ') AS ' . uniqid('a');
            $total    = (int)($this->db->query($countSql, $baseValues, 'read')[0]['tot'] ?? 0);

            // Paginated data
            $qo->setLimit($limit, $offset);
            [$dataSql, $dataValues] = $qo->getSql();
            $data = $this->db->query($dataSql, $dataValues, 'read') ?: [];

        } catch (\Throwable $e) {
            Router::error('Invalid ShortSQL: ' . $e->getMessage(), 'INVALID_SHORTSQL', 400);
            return;
        }

        Router::respond($data, [
            'total_count'  => $total,
            'filter_count' => $total,
            'page'         => $page,
            'limit'        => $limit,
            'total_pages'  => (int)ceil($total / $limit),
        ]);
    }

    /**
     * Build a WHERE clause from ?filter or simple field=value params.
     * ShortSQL is handled separately before this method is called.
     *
     * Priority:
     *   1. ?filter  (Directus-style JSON / PHP array)
     *   2. ?search  (LIKE on preview fields)
     *   3. ?{field}=value  (exact match, validated against config)
     *
     * @return array [string|null $where, array $values]
     */
    private function buildWhere(string $table): array
    {
        // Priority 1: Directus-style filter
        if (!empty($_GET['filter'])) {
            $fp = new Filter($table);
            return $fp->parse();
        }

        // Priority 2 + 3: simple params + ?search
        $reserved = [
            'page', 'limit', 'sort', 'fields', 'filter',
            'shortsql', 'search', 'api_key', 'pretty',
        ];

        $parts  = [];
        $values = [];

        $configFields   = array_column($this->cfg->get("tables.$table.fields") ?? [], 'name');
        $configFields[] = 'id';

        foreach ($_GET as $k => $v) {
            if (in_array($k, $reserved, true)) { continue; }
            $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $k);
            if (!$clean || !in_array($clean, $configFields, true)) { continue; }
            $parts[]  = "$table.$clean = ?";
            $values[] = $v;
        }

        // ?search=... — LIKE on preview fields
        if (!empty($_GET['search'])) {
            $preview = $this->cfg->get("tables.$table.preview") ?? [];
            if (!empty($preview)) {
                $searchParts = [];
                foreach ($preview as $f) {
                    $searchParts[] = "$table.$f LIKE ?";
                    $values[]      = '%' . $_GET['search'] . '%';
                }
                $parts[] = '(' . implode(' OR ', $searchParts) . ')';
            }
        }

        return [empty($parts) ? null : implode(' AND ', $parts), $values];
    }

    /**
     * Parse ?sort param.
     * Format: "field,-other_field"  → "table.field ASC, table.other_field DESC"
     */
    private function parseSort(?string $sort, string $table): ?string
    {
        if (!$sort) { return null; }
        $parts = [];
        foreach (array_map('trim', explode(',', $sort)) as $s) {
            if (!$s) { continue; }
            $dir = 'ASC';
            if ($s[0] === '-') { $dir = 'DESC'; $s = substr($s, 1); }
            $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $s);
            if ($clean) { $parts[] = "$table.$clean $dir"; }
        }
        return $parts ? implode(', ', $parts) : null;
    }

    /**
     * Parse ?fields param.
     * Format: "id,name,*"  → validated SQL column list.
     * Unknown or unsafe field names are silently dropped.
     */
    private function parseFields(string $fields, string $table): string
    {
        if ($fields === '*') { return "$table.*"; }

        $configFields   = array_column($this->cfg->get("tables.$table.fields") ?? [], 'name');
        $configFields[] = 'id';

        $parts = [];
        foreach (array_map('trim', explode(',', $fields)) as $f) {
            $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $f);
            if ($clean && in_array($clean, $configFields, true)) {
                $parts[] = "$table.$clean";
            }
        }
        return $parts ? implode(', ', $parts) : "$table.*";
    }

    /**
     * Returns true when $table is a valid, non-system user table.
     * Emits a Router::error() and returns false otherwise.
     */
    private function assertUserTable(string $table): bool
    {
        $stripped     = str_replace($this->prefix, '', $table);
        $systemNames  = $this->systemTableNames();

        if (in_array($stripped, $systemNames, true)) {
            Router::error('System tables cannot be queried', 'FORBIDDEN_TABLE', 403);
            return false;
        }
        if (!$this->cfg->get("tables.$table")) {
            Router::error("Unknown table: $table", 'UNKNOWN_TABLE', 404);
            return false;
        }
        return true;
    }

    /**
     * Returns system table *short* names (without prefix) from Manage.
     */
    private function systemTableNames(): array
    {
        return (new Manage($this->db, $this->prefix))->available_tables;
    }
}
