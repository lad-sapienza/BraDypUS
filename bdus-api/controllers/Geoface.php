<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Aug 22, 2012
 */

class Geoface extends \Bdus\Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    // v5 methods
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Returns GeoJSON FeatureCollection for all geometries linked to records
     * in a given table, optionally filtered by the active search.
     *
     * GET ?obj=geoface_ctrl&method=getGeoJson&tb=TABLE
     *     [&filter[field][_op]=value]         (Directus-style JSON filter, bracket notation)
     *     [&search_type=sqlExpert]
     *     [&querytext=SQL_WHERE_CLAUSE]     (search_type=sqlExpert)
     *
     * Response:
     * {
     *   "status": "success",
     *   "geojson": { "type": "FeatureCollection", "features": [...] },
     *   "meta": {
     *     "tb_id": "full_table_name",
     *     "tb_label": "Human label",
     *     "canUserEdit": true|false,
     *     "layers": [...],
     *     "preview_fields": ["field1", "field2"],
     *     "id_field": "fieldname"
     *   }
     * }
     */
    public function getGeoJson(): void
    {
        $tb = $this->get['tb'] ?? null;

        if (!$tb) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        if (!\Auth\Authorization::can('read')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        try {
            $searchType = $this->get['search_type'] ?? $this->post['search_type'] ?? null;

            // Delegate WHERE-building to QueryFromRequest — same as record_ctrl.
            // All search types (filter, sqlExpert, fast, all) are handled
            // centrally; this module knows nothing about how the predicate is built.
            $filterRaw = $this->get['filter'] ?? $this->post['filter'] ?? null;
            if (is_string($filterRaw)) {
                $filterRaw = json_decode($filterRaw, true);
            }
            $qRequest = ['tb' => $tb, 'type' => $searchType ?? 'all'];
            if (is_array($filterRaw)) {
                $qRequest['type']   = 'filter';
                $qRequest['filter'] = $filterRaw;
            } elseif ($searchType === 'sqlExpert') {
                $qRequest['querytext'] = $this->get['querytext'] ?? $this->post['querytext'] ?? '';
                $qRequest['join']      = $this->get['join']      ?? $this->post['join']      ?? '';
            }
            [$userWhere, $userValues] = (new \SQL\QueryFromRequest($this->db, $this->cfg, $qRequest))
                ->getWhereClause();
            // '1=1' means "no user filter" — omit from the final JOIN query.
            if ($userWhere === '1=1') {
                $userWhere  = '';
                $userValues = [];
            }

            // Build SELECT field list: id + preview fields + geo_id + geometry
            $previewFields = $this->cfg->get("tables.{$tb}.preview") ?? [];
            $part = ["{$tb}.id AS id"];
            $previewLabels = [];

            foreach ($previewFields as $fldId) {
                if ($fldId === 'id') {
                    continue;
                }
                $label = $this->cfg->get("tables.{$tb}.fields.{$fldId}.label") ?? $fldId;
                $part[] = "{$tb}.{$fldId} AS \"{$label}\"";
                $previewLabels[] = $label;
            }

            $part[] = 'bdus_geodata.id AS geo_id';
            $part[] = 'geometry';

            // Qualify WHERE clause: if it references bare `id`, prefix with table name
            if ($userWhere && !preg_match('/' . preg_quote($tb, '/') . '\.id/', $userWhere)) {
                $userWhere = str_replace('id', $tb . '.id', $userWhere);
            }

            $sql = 'SELECT ' . implode(', ', $part)
                . " FROM {$tb}"
                . ' LEFT JOIN bdus_geodata'
                . " ON {$tb}.id = bdus_geodata.id_link"
                . " AND bdus_geodata.table_link = '{$tb}'"
                . ' WHERE geometry IS NOT NULL'
                . ($userWhere ? ' AND ' . $userWhere : '');

            $res = $this->db->query($sql, $userValues);

            $geojson = $this->toGeoJSON($tb, $res ?: []);

            // Load custom layers from DB (or file fallback for pre-M014 apps).
            $layers = \Config\GeofaceConfig::getLayers($this->db);

            $idField = $this->cfg->get("tables.{$tb}.id_field") ?? 'id';

            $this->returnJson([
                'status'  => 'success',
                'geojson' => $geojson,
                'meta'    => [
                    'tb_id'          => $tb,
                    'tb_label'       => $this->cfg->get("tables.{$tb}.label") ?? $tb,
                    'canUserEdit'    => \Auth\Authorization::can('edit'),
                    'layers'         => $layers,
                    'preview_fields' => $previewLabels,
                    'id_field'       => $idField,
                    'has_fuzzy_date' => (bool) $this->cfg->get("tables.{$tb}.fuzzy_date"),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_getting_geodata']);
        }
    }

    /**
     * Saves a new geometry linked to a specific record.
     *
     * POST ?obj=geoface_ctrl&method=saveNew
     * Body: { tb, id, geometry }  — geometry is a GeoJSON geometry object (JSON string or array)
     *
     * Response: { status, code, geo_id }
     */
    public function saveNew(): void
    {
        $tb       = $this->post['tb']       ?? $this->get['tb']       ?? null;
        $id       = $this->post['id']       ?? $this->get['id']       ?? null;
        $geometry = $this->post['geometry'] ?? $this->get['geometry'] ?? null;

        if (!$tb || $id === null || !$geometry) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        if (!\Auth\Authorization::can('add_new')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        try {
            // Accept geometry as JSON string or already-decoded array
            if (is_string($geometry)) {
                $geometry = json_decode($geometry, true);
            }

            $wkt = \geoPHP\geoPHP::load(json_encode($geometry), 'geojson')->out('wkt');

            $ok = $this->db->query(
                'INSERT INTO bdus_geodata (table_link, id_link, geometry) VALUES (?, ?, ?)',
                [$tb, (int)$id, $wkt],
                'boolean'
            );

            if (!$ok) {
                throw new \Exception('INSERT INTO geodata returned false');
            }

            // Retrieve the newly inserted row id
            $row = $this->db->query(
                'SELECT id FROM bdus_geodata WHERE table_link = ? AND id_link = ? ORDER BY id DESC LIMIT 1',
                [$tb, (int)$id]
            );
            $geoId = $row[0]['id'] ?? null;

            $this->returnJson(['status' => 'success', 'code' => 'ok_insert_geodata', 'geo_id' => $geoId]);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_insert_geodata']);
        }
    }

    /**
     * Updates one or more existing geometries in the geodata table.
     *
     * POST ?obj=geoface_ctrl&method=updateGeometry
     * Body: { geodata: [ { id, geometry }, ... ] }  — geometry is a GeoJSON geometry object
     *
     * Response: { status, code }
     */
    public function updateGeometry(): void
    {
        $geodata = $this->post['geodata'] ?? [];

        if (empty($geodata)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        if (!\Auth\Authorization::can('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        try {
            foreach ($geodata as $row) {
                $geomRaw = $row['geometry'] ?? null;
                $geoId   = $row['id']       ?? null;

                if (!$geomRaw || $geoId === null) {
                    continue;
                }

                if (is_string($geomRaw)) {
                    $geomRaw = json_decode($geomRaw, true);
                }

                $wkt = \geoPHP\geoPHP::load(json_encode($geomRaw), 'geojson')->out('wkt');

                $ok = $this->db->query(
                    'UPDATE bdus_geodata SET geometry = ? WHERE id = ?',
                    [$wkt, (int)$geoId],
                    'boolean'
                );

                if (!$ok) {
                    throw new \Exception("UPDATE geodata returned false for id={$geoId}");
                }
            }

            $this->returnJson(['status' => 'success', 'code' => 'ok_update_geometry']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_update_geometry']);
        }
    }

    /**
     * Deletes one or more geometries from the geodata table.
     *
     * POST ?obj=geoface_ctrl&method=eraseGeometry
     * Body: { ids: [ geo_id, ... ] }
     *
     * Response: { status, code }
     */
    public function eraseGeometry(): void
    {
        $ids = $this->post['ids'] ?? [];

        if (empty($ids)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        if (!\Auth\Authorization::can('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        try {
            foreach ($ids as $id) {
                $ok = $this->db->query(
                    'DELETE FROM bdus_geodata WHERE id = ?',
                    [(int)$id],
                    'boolean'
                );

                if (!$ok) {
                    throw new \Exception("DELETE geodata returned false for id={$id}");
                }
            }

            $this->returnJson(['status' => 'success', 'code' => 'ok_delete_geodata']);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_delete_geodata']);
        }
    }

    /**
     * Converts an array of DB rows (each containing a WKT `geometry` column)
     * to a GeoJSON FeatureCollection.
     */
    private function toGeoJSON(string $tb, array $rows): array
    {
        $geo = ['type' => 'FeatureCollection', 'features' => []];

        foreach ($rows as $r) {
            $geom = $r['geometry'] ?? $r[$tb . '.geometry'] ?? null;

            if (!$geom) {
                error_log('No valid geometry column found in row: ' . var_export($r, true));
                continue;
            }

            try {
                $geoPHP = \geoPHP\geoPHP::load($geom, 'wkt');
            } catch (\Throwable $th) {
                error_log("WKT geometry {$geom} could not be parsed: " . var_export($r, true));
                continue;
            }

            $feat = [
                'type'     => 'Feature',
                'geometry' => json_decode($geoPHP->out('geojson'), true),
            ];
            unset($r['geometry']);
            if ($r) {
                $feat['properties'] = $r;
            }
            $geo['features'][] = $feat;
        }

        return $geo;
    }
}
