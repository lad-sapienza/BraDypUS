<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Zotero integration controller.
 *
 * Manages Zotero library configuration, proxies searches to the Zotero API,
 * handles citation links between BraDypUS records and Zotero items, and
 * provides cache sync (on-demand per record and bulk admin sync).
 *
 * Routes (see Router.php):
 *   GET    /api/zotero/libs                — list configured libraries
 *   POST   /api/zotero/lib                 — add a library
 *   DELETE /api/zotero/lib/{id}            — remove a library
 *   GET    /api/zotero/search              — proxy search to Zotero
 *   GET    /api/zotero/links/{tb}/{id}     — get all links for a record
 *   POST   /api/zotero/link               — add a link (fetches and caches citation)
 *   PATCH  /api/zotero/link/{id}          — update pages / notes / sort
 *   DELETE /api/zotero/link/{id}          — remove a link
 *   POST   /api/zotero/sync/{tb}/{id}     — sync cache for one record (background)
 *   POST   /api/zotero/sync               — sync all referenced items (admin)
 */

use DB\System\Manage;
use Zotero\Client;
use Zotero\ZoteroException;

class zotero_ctrl extends Controller
{
    // ── Library management (admin) ────────────────────────────────────────────

    /**
     * GET /api/zotero/libs
     * Returns all configured Zotero libraries (api_key redacted).
     */
    public function getLibs(): void
    {
        if (!\utils::canUser('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $manage = new Manage($this->db);
        $rows   = $manage->getBySQL('bdus_zotero_libs', '1=1 ORDER BY name') ?: [];

        foreach ($rows as &$r) {
            // Never expose the API key over the wire.
            $r['has_api_key'] = !empty($r['api_key']);
            unset($r['api_key']);
        }
        unset($r);

        $this->returnJson(['status' => 'success', 'libs' => $rows]);
    }

    /**
     * POST /api/zotero/lib
     * Body: { type, zotero_id, name, api_key?, citation_style? }
     */
    public function addLib(): void
    {
        if (!\utils::canUser('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $type          = $this->post['type']           ?? '';
        $zoteroId      = trim($this->post['zotero_id'] ?? '');
        $name          = trim($this->post['name']       ?? '');
        $apiKey        = trim($this->post['api_key']    ?? '') ?: null;
        $citationStyle = trim($this->post['citation_style'] ?? '') ?: Client::DEFAULT_STYLE;

        if (!in_array($type, ['user', 'group'], true) || !$zoteroId || !$name) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $manage = new Manage($this->db);
        $id = $manage->addRow('bdus_zotero_libs', [
            'type'           => $type,
            'zotero_id'      => $zoteroId,
            'name'           => $name,
            'api_key'        => $apiKey,
            'citation_style' => $citationStyle,
            'created_at'     => time(),
        ]);

        $this->returnJson(['status' => 'success', 'code' => 'ok_lib_added', 'id' => $id]);
    }

    /**
     * DELETE /api/zotero/lib/{id}
     * Deletes the library and all its links (CASCADE in DB).
     */
    public function deleteLib(): void
    {
        if (!\utils::canUser('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $id = (int) ($this->get['id'] ?? 0);
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $manage = new Manage($this->db);
        $manage->deleteRow('bdus_zotero_libs', $id);

        $this->returnJson(['status' => 'success', 'code' => 'ok_lib_deleted']);
    }

    // ── Search proxy (edit+) ──────────────────────────────────────────────────

    /**
     * GET /api/zotero/search?lib_id={id}&q={query}&limit={n}
     *
     * Proxies the query to Zotero and returns each item with:
     *   key, author_year, full_citation (HTML), zotero_version, zotero_url
     */
    public function search(): void
    {
        if (!\utils::canUser('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $libId = (int) ($this->get['lib_id'] ?? 0);
        $q     = trim($this->get['q'] ?? '');
        $limit = min(100, max(1, (int) ($this->get['limit'] ?? 25)));

        if (!$libId || !$q) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $lib = $this->getLib($libId);
        if (!$lib) {
            $this->returnJson(['status' => 'error', 'code' => 'lib_not_found']);
            return;
        }

        $client = $this->makeClient($lib);

        try {
            $items   = $client->search($q, $limit);
            $results = [];

            foreach ($items as $item) {
                $key     = $item['key'] ?? null;
                if (!$key) {
                    continue;
                }
                $style    = $lib['citation_style'] ?: Client::DEFAULT_STYLE;
                $citation = $client->getFormattedCitation($key, $style);

                $results[] = [
                    'key'          => $key,
                    'author_year'  => $client->extractAuthorYear($item),
                    'full_citation' => $citation,
                    'zotero_version' => $item['version'] ?? null,
                    'zotero_url'   => $client->buildPublicUrl($key),
                    'title'        => $item['data']['title'] ?? '',
                ];
            }

            $this->returnJson(['status' => 'success', 'results' => $results]);

        } catch (ZoteroException $e) {
            $this->log->error('Zotero search error: ' . $e->getMessage());
            $this->returnJson(['status' => 'error', 'code' => 'zotero_api_error', 'detail' => $e->getMessage()]);
        }
    }

    // ── Links on records (edit+) ──────────────────────────────────────────────

    /**
     * GET /api/zotero/links/{tb}/{id}
     * Returns all Zotero links for a record, ordered by sort.
     */
    public function getLinks(): void
    {
        if (!\utils::canUser('read')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $tb       = $this->get['tb']   ?? '';
        $recordId = (int) ($this->get['id'] ?? 0);

        if (!$tb || !$recordId) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $rows = $this->db->query(
            "SELECT l.*, libs.type AS lib_type, libs.zotero_id AS lib_zotero_id
               FROM bdus_zotero_links l
               JOIN bdus_zotero_libs libs ON libs.id = l.lib_id
              WHERE l.tb = ? AND l.record_id = ?
              ORDER BY l.sort, l.id",
            [$tb, $recordId],
            'read'
        ) ?: [];

        foreach ($rows as &$r) {
            $r['zotero_url'] = $this->buildUrl($r['lib_type'], $r['lib_zotero_id'], $r['zotero_key']);
            unset($r['lib_type'], $r['lib_zotero_id']);
        }
        unset($r);

        $this->returnJson(['status' => 'success', 'links' => $rows]);
    }

    /**
     * POST /api/zotero/link
     * Body: { tb, record_id, lib_id, zotero_key, pages?, notes?, sort? }
     *
     * Immediately fetches and caches the citation from Zotero.
     */
    public function addLink(): void
    {
        if (!\utils::canUser('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $tb        = $this->post['tb']         ?? '';
        $recordId  = (int) ($this->post['record_id'] ?? 0);
        $libId     = (int) ($this->post['lib_id']    ?? 0);
        $zoteroKey = trim($this->post['zotero_key']  ?? '');
        $pages     = $this->post['pages'] ?? null;
        $notes     = $this->post['notes'] ?? null;
        $sort      = isset($this->post['sort']) ? (int) $this->post['sort'] : null;

        if (!$tb || !$recordId || !$libId || !$zoteroKey) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $lib = $this->getLib($libId);
        if (!$lib) {
            $this->returnJson(['status' => 'error', 'code' => 'lib_not_found']);
            return;
        }

        // Fetch and cache citation immediately on add.
        $cacheData = $this->fetchCacheData($lib, $zoteroKey);

        $manage = new Manage($this->db);
        $id = $manage->addRow('bdus_zotero_links', array_merge([
            'tb'         => $tb,
            'record_id'  => $recordId,
            'lib_id'     => $libId,
            'zotero_key' => $zoteroKey,
            'pages'      => $pages,
            'notes'      => $notes,
            'sort'       => $sort,
            'created_at' => time(),
        ], $cacheData));

        $this->returnJson(['status' => 'success', 'code' => 'ok_link_added', 'id' => $id]);
    }

    /**
     * PATCH /api/zotero/link/{id}
     * Body: { pages?, notes?, sort? }
     */
    public function editLink(): void
    {
        if (!\utils::canUser('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $id      = (int) ($this->get['id'] ?? 0);
        $allowed = ['pages', 'notes', 'sort'];
        $data    = array_intersect_key($this->post, array_flip($allowed));

        if (!$id || empty($data)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $manage = new Manage($this->db);
        $manage->editRow('bdus_zotero_links', $id, $data);

        $this->returnJson(['status' => 'success', 'code' => 'ok_link_updated']);
    }

    /**
     * DELETE /api/zotero/link/{id}
     */
    public function deleteLink(): void
    {
        if (!\utils::canUser('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $id = (int) ($this->get['id'] ?? 0);
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $manage = new Manage($this->db);
        $manage->deleteRow('bdus_zotero_links', $id);

        $this->returnJson(['status' => 'success', 'code' => 'ok_link_deleted']);
    }

    // ── Cache sync ────────────────────────────────────────────────────────────

    /**
     * POST /api/zotero/sync/{tb}/{id}
     *
     * Syncs citation cache for all Zotero links on a single record.
     * Designed to be called in the background from the frontend after record load.
     *
     * Returns: { status, updated: N, detached: N }
     */
    public function syncRecord(): void
    {
        if (!\utils::canUser('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $tb       = $this->get['tb']   ?? '';
        $recordId = (int) ($this->get['id'] ?? 0);

        if (!$tb || !$recordId) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $links = $this->db->query(
            "SELECT l.id, l.lib_id, l.zotero_key, l.zotero_version
               FROM bdus_zotero_links l
              WHERE l.tb = ? AND l.record_id = ?",
            [$tb, $recordId],
            'read'
        ) ?: [];

        [$updated, $detached] = $this->syncLinks($links);

        $this->returnJson([
            'status'   => 'success',
            'updated'  => $updated,
            'detached' => $detached,
        ]);
    }

    /**
     * POST /api/zotero/sync
     *
     * Admin: syncs citation cache for every unique (lib_id, zotero_key) pair
     * referenced in bdus_zotero_links.
     *
     * Returns: { status, updated: N, detached: N, total: N }
     */
    public function syncAll(): void
    {
        if (!\utils::canUser('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        // Fetch one representative row per unique item (we only need lib_id + key).
        $links = $this->db->query(
            "SELECT MIN(id) AS id, lib_id, zotero_key, MIN(zotero_version) AS zotero_version
               FROM bdus_zotero_links
              GROUP BY lib_id, zotero_key",
            [],
            'read'
        ) ?: [];

        [$updated, $detached] = $this->syncLinks($links);

        $this->returnJson([
            'status'   => 'success',
            'total'    => count($links),
            'updated'  => $updated,
            'detached' => $detached,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Sync a list of link rows (each with lib_id, zotero_key, zotero_version).
     *
     * Groups links by library so we batch requests per library.
     * Returns [updated_count, detached_count].
     */
    private function syncLinks(array $links): array
    {
        if (empty($links)) {
            return [0, 0];
        }

        // Group by lib_id.
        $byLib = [];
        foreach ($links as $link) {
            $byLib[$link['lib_id']][] = $link;
        }

        $updated  = 0;
        $detached = 0;

        foreach ($byLib as $libId => $libLinks) {
            $lib = $this->getLib((int) $libId);
            if (!$lib) {
                continue;
            }

            $client = $this->makeClient($lib);
            $keys   = array_column($libLinks, 'zotero_key');

            try {
                $fetched = $client->getItems($keys); // keyed by zotero_key
            } catch (ZoteroException $e) {
                $this->log->error("Zotero sync error lib {$libId}: " . $e->getMessage());
                continue;
            }

            $style = $lib['citation_style'] ?: Client::DEFAULT_STYLE;

            foreach ($libLinks as $link) {
                $key  = $link['zotero_key'];
                $item = $fetched[$key] ?? null;

                if (!$item) {
                    // Item no longer exists in Zotero → mark as detached.
                    $this->db->query(
                        "UPDATE bdus_zotero_links SET detached = 1, synced_at = ?
                          WHERE lib_id = ? AND zotero_key = ?",
                        [time(), $libId, $key],
                        'boolean'
                    );
                    $detached++;
                    continue;
                }

                $remoteVersion = (int) ($item['version'] ?? 0);
                if ($remoteVersion === (int) ($link['zotero_version'] ?? 0)) {
                    continue; // Up to date — nothing to do.
                }

                // Fetch fresh formatted citation.
                try {
                    $citation = $client->getFormattedCitation($key, $style);
                } catch (ZoteroException $e) {
                    $this->log->warning("Zotero citation fetch failed for {$key}: " . $e->getMessage());
                    $citation = null;
                }

                $this->db->query(
                    "UPDATE bdus_zotero_links
                        SET author_year    = ?,
                            full_citation  = ?,
                            zotero_version = ?,
                            synced_at      = ?,
                            detached       = 0
                      WHERE lib_id = ? AND zotero_key = ?",
                    [
                        $client->extractAuthorYear($item),
                        $citation,
                        $remoteVersion,
                        time(),
                        $libId,
                        $key,
                    ],
                    'boolean'
                );
                $updated++;
            }
        }

        return [$updated, $detached];
    }

    /**
     * Fetches citation data from Zotero for a single item.
     * Returns an array ready to merge into a bdus_zotero_links row.
     * On error returns empty cache fields (item can still be saved).
     */
    private function fetchCacheData(array $lib, string $zoteroKey): array
    {
        $style  = $lib['citation_style'] ?: Client::DEFAULT_STYLE;
        $client = $this->makeClient($lib);

        try {
            $items = $client->getItems([$zoteroKey]);
            $item  = $items[$zoteroKey] ?? null;

            if (!$item) {
                return ['detached' => 1, 'synced_at' => time()];
            }

            $citation = $client->getFormattedCitation($zoteroKey, $style);

            return [
                'author_year'    => $client->extractAuthorYear($item),
                'full_citation'  => $citation,
                'zotero_version' => (int) ($item['version'] ?? 0),
                'synced_at'      => time(),
                'detached'       => 0,
            ];
        } catch (ZoteroException $e) {
            $this->log->warning("Zotero cache fetch failed for {$zoteroKey}: " . $e->getMessage());
            return ['synced_at' => time()];
        }
    }

    /** Loads a library row (with api_key). Returns null if not found. */
    private function getLib(int $id): ?array
    {
        $manage = new Manage($this->db);
        $row    = $manage->getById('bdus_zotero_libs', $id);
        return $row ?: null;
    }

    /** Instantiates a Zotero\Client from a library row. */
    private function makeClient(array $lib): Client
    {
        return new Client(
            $lib['type'],
            $lib['zotero_id'],
            $lib['api_key'] ?? null
        );
    }

    /** Builds public Zotero URL; null for user libraries. */
    private function buildUrl(string $type, string $zoteroId, string $key): ?string
    {
        if ($type === 'user') {
            return null;
        }
        return "https://www.zotero.org/groups/{$zoteroId}/items/{$key}";
    }
}
