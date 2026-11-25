<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Zotero;

use DB\DBInterface;
use DB\System\Manage;
use Monolog\Logger;

/**
 * The Zotero logic that does not depend on an HTTP request: libraries, search,
 * citation cache, and the links between records and Zotero items. Shared by the
 * REST controller (Bdus\Controllers\Zotero) and the MCP server, so both write
 * links the same way. Privileges are the caller's business.
 */
class Links
{
    public function __construct(
        private readonly DBInterface $db,
        private readonly ?Logger $log = null,
    ) {}

    /** Loads a library row (with api_key). Returns null if not found. */
    public function getLib(int $id): ?array
    {
        $row = (new Manage($this->db))->getById('bdus_zotero_libs', $id);
        return $row ?: null;
    }

    /** Configured libraries, without api_key (`has_api_key` instead). */
    public function libs(): array
    {
        $rows = (new Manage($this->db))->getBySQL('bdus_zotero_libs', '1=1 ORDER BY name') ?: [];
        foreach ($rows as &$r) {
            $r['has_api_key'] = !empty($r['api_key']);
            unset($r['api_key']);
        }
        return $rows;
    }

    /** Instantiates a Zotero\Client from a library row. */
    public function makeClient(array $lib): Client
    {
        return new Client($lib['type'], $lib['zotero_id'], $lib['api_key'] ?? null);
    }

    /**
     * Searches the library. Each item: key, author_year, full_citation (HTML),
     * zotero_version, zotero_url, title.
     *
     * @throws ZoteroException
     */
    public function search(array $lib, string $q, int $limit): array
    {
        $client  = $this->makeClient($lib);
        $style   = $lib['citation_style'] ?: Client::DEFAULT_STYLE;
        $results = [];
        foreach ($client->search($q, $limit) as $item) {
            $key = $item['key'] ?? null;
            if (!$key) {
                continue;
            }
            $results[] = [
                'key'            => $key,
                'author_year'    => $client->extractAuthorYear($item),
                'full_citation'  => $client->getFormattedCitation($key, $style),
                'zotero_version' => $item['version'] ?? null,
                'zotero_url'     => $client->buildPublicUrl($key),
                'title'          => $item['data']['title'] ?? '',
            ];
        }
        return $results;
    }

    /**
     * Fetches citation data from Zotero for a single item.
     * Returns an array ready to merge into a bdus_zotero_links row.
     * On error returns empty cache fields (the link can still be saved).
     */
    public function fetchCacheData(array $lib, string $zoteroKey): array
    {
        $style  = $lib['citation_style'] ?: Client::DEFAULT_STYLE;
        $client = $this->makeClient($lib);

        try {
            $item = $client->getItems([$zoteroKey])[$zoteroKey] ?? null;
            if (!$item) {
                return ['detached' => 1, 'synced_at' => time()];
            }
            return [
                'author_year'    => $client->extractAuthorYear($item),
                'full_citation'  => $client->getFormattedCitation($zoteroKey, $style),
                'zotero_version' => (int) ($item['version'] ?? 0),
                'synced_at'      => time(),
                'detached'       => 0,
            ];
        } catch (ZoteroException $e) {
            $this->log?->warning("Zotero cache fetch failed for {$zoteroKey}: " . $e->getMessage());
            return ['synced_at' => time()];
        }
    }

    /** Links of a record, in order, with the public Zotero URL (null for user libraries). */
    public function forRecord(string $tb, int $recordId): array
    {
        return $this->forRecords($tb, [$recordId])[$recordId] ?? [];
    }

    /**
     * Links of several records of a table, in order.
     *
     * @param int[] $recordIds
     * @return array<int, array[]> record id => links
     */
    public function forRecords(string $tb, array $recordIds): array
    {
        $recordIds = array_values(array_unique(array_map('intval', $recordIds)));
        if (!$recordIds) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($recordIds), '?'));
        $rows = $this->db->query(
            "SELECT l.*, libs.type AS lib_type, libs.zotero_id AS lib_zotero_id, libs.name AS lib_name
               FROM bdus_zotero_links l
               JOIN bdus_zotero_libs libs ON libs.id = l.lib_id
              WHERE l.tb = ? AND l.record_id IN ({$in})
              ORDER BY l.sort, l.id",
            array_merge([$tb], $recordIds),
            'read'
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $r['zotero_url'] = self::buildUrl($r['lib_type'], $r['lib_zotero_id'], $r['zotero_key']);
            unset($r['lib_type'], $r['lib_zotero_id']);
            $out[(int) $r['record_id']][] = $r;
        }
        return $out;
    }

    /** The link row $id, or null. */
    public function find(int $id): ?array
    {
        return $this->db->query('SELECT * FROM bdus_zotero_links WHERE id = ?', [$id], 'read')[0] ?? null;
    }

    /**
     * Adds a link. The citation is fetched from Zotero now unless $cache (from
     * fetchCacheData()) says what to store.
     *
     * @return int|null id of the new link; null when the library does not exist
     */
    public function add(string $tb, int $recordId, int $libId, string $zoteroKey, mixed $pages, mixed $notes, ?int $sort, ?array $cache = null): ?int
    {
        $lib = $this->getLib($libId);
        if (!$lib) {
            return null;
        }
        $id = (new Manage($this->db))->addRow('bdus_zotero_links', array_merge([
            'tb'         => $tb,
            'record_id'  => $recordId,
            'lib_id'     => $libId,
            'zotero_key' => $zoteroKey,
            'pages'      => $pages,
            'notes'      => $notes,
            'sort'       => $sort,
            'created_at' => time(),
        ], $cache ?? $this->fetchCacheData($lib, $zoteroKey)));
        return (int) $id;
    }

    /** Changes pages / notes / sort of a link. */
    public function edit(int $id, array $data): void
    {
        (new Manage($this->db))->editRow('bdus_zotero_links', $id, array_intersect_key($data, array_flip(['pages', 'notes', 'sort'])));
    }

    public function delete(int $id): void
    {
        (new Manage($this->db))->deleteRow('bdus_zotero_links', $id);
    }

    /** Builds public Zotero URL; null for user libraries. */
    public static function buildUrl(string $type, string $zoteroId, string $key): ?string
    {
        return $type === 'user' ? null : "https://www.zotero.org/groups/{$zoteroId}/items/{$key}";
    }
}
