<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Aug 22, 2012
 */

class geoface_ctrl extends Controller
{
    // ═══════════════════════════════════════════════════════════════════════
    // v5 methods
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Returns GeoJSON FeatureCollection for all geometries linked to records
     * in a given table, optionally filtered by the active search.
     *
     * GET ?obj=geoface_ctrl&method=getGeoJson&tb=TABLE
     *     [&search_type=shortSql|sqlExpert|advanced]
     *     [&where=SHORTSQL_STRING]          (search_type=shortSql)
     *     [&querytext=SQL_WHERE_CLAUSE]     (search_type=sqlExpert)
     *     [&adv=JSON_ENCODED_ADV_ROWS]      (search_type=advanced)
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

        if (!\utils::canUser('read')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        try {
            $searchType = $this->get['search_type'] ?? $this->post['search_type'] ?? null;

            $userWhere  = '';
            $userValues = [];

            switch ($searchType) {
                case 'shortSql':
                    $whereStr = trim($this->get['where'] ?? '');
                    if ($whereStr !== '' && $whereStr !== '1') {
                        $dblUnder = strpos($tb, '__');
                        $tbPrefix = ($dblUnder !== false) ? substr($tb, 0, $dblUnder + 2) : '';
                        $parser = new \SQL\ShortSql\ParseShortSql($tbPrefix, $this->cfg);
                        $parser->parseAll('@' . $tb . '~?' . $whereStr, true);
                        [$userWhere, $userValues] = $parser->getSql(true);
                    }
                    break;

                case 'sqlExpert':
                    $querytext = trim($this->get['querytext'] ?? $this->post['querytext'] ?? '');
                    if ($querytext !== '') {
                        $userWhere  = '(' . $querytext . ')';
                        $userValues = [];
                    }
                    break;

                case 'advanced':
                    // TODO: implement advanced search WHERE building for geoface
                    // For now fall through to no filter (same as 'all')
                    break;

                default:
                    // No filter — return all geometries for the table
                    break;
            }

            // Build SELECT field list: id + preview fields + geo_id + geometry
            $previewFields = $this->cfg->get("tables.{$tb}.preview") ?? [];
            $part = ["{$tb}.id AS id"];

            foreach ($previewFields as $fldId) {
                if ($fldId === 'id') {
                    continue;
                }
                $label = $this->cfg->get("tables.{$tb}.fields.{$fldId}.label") ?? $fldId;
                $part[] = "{$tb}.{$fldId} AS \"{$label}\"";
            }

            $part[] = $this->prefix . 'geodata.id AS geo_id';
            $part[] = 'geometry';

            // Qualify WHERE clause: if it references bare `id`, prefix with table name
            if ($userWhere && !preg_match('/' . preg_quote($tb, '/') . '\.id/', $userWhere)) {
                $userWhere = str_replace('id', $tb . '.id', $userWhere);
            }

            $sql = 'SELECT ' . implode(', ', $part)
                . " FROM {$tb}"
                . ' LEFT JOIN ' . $this->prefix . 'geodata'
                . " ON {$tb}.id = " . $this->prefix . "geodata.id_link"
                . " AND " . $this->prefix . "geodata.table_link = '{$tb}'"
                . ' WHERE geometry IS NOT NULL'
                . ($userWhere ? ' AND ' . $userWhere : '');

            $res = $this->db->query($sql, $userValues);

            $geojson = \utils::multiArray2GeoJSON($tb, $res ?: []);

            // Load custom layers from geodata/index.json
            $layers = [];
            if (defined('PROJ_DIR') && file_exists(PROJ_DIR . 'geodata/index.json')) {
                $layers = json_decode(file_get_contents(PROJ_DIR . 'geodata/index.json'), true) ?? [];
            }

            $idField = $this->cfg->get("tables.{$tb}.id_field") ?? 'id';

            $this->returnJson([
                'status'  => 'success',
                'geojson' => $geojson,
                'meta'    => [
                    'tb_id'          => $tb,
                    'tb_label'       => $this->cfg->get("tables.{$tb}.label") ?? $tb,
                    'canUserEdit'    => \utils::canUser('edit'),
                    'layers'         => $layers,
                    'preview_fields' => $previewFields,
                    'id_field'       => $idField,
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

        if (!\utils::canUser('add_new')) {
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
                'INSERT INTO ' . $this->prefix . 'geodata (table_link, id_link, geometry) VALUES (?, ?, ?)',
                [$tb, (int)$id, $wkt],
                'boolean'
            );

            if (!$ok) {
                throw new \Exception('INSERT INTO geodata returned false');
            }

            // Retrieve the newly inserted row id
            $row = $this->db->query(
                'SELECT id FROM ' . $this->prefix . 'geodata WHERE table_link = ? AND id_link = ? ORDER BY id DESC LIMIT 1',
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

        if (!\utils::canUser('edit')) {
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
                    'UPDATE ' . $this->prefix . 'geodata SET geometry = ? WHERE id = ?',
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

        if (!\utils::canUser('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        try {
            foreach ($ids as $id) {
                $ok = $this->db->query(
                    'DELETE FROM ' . $this->prefix . 'geodata WHERE id = ?',
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

    // ═══════════════════════════════════════════════════════════════════════
    // Legacy v4 methods — kept for backwards compatibility
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Saves new geometry data to geodata table, linked to table, id
     *
     * @deprecated v5 — replaced by saveNew()
     * @throws \Exception
     */
    public function saveNew_v4()
    {
        $tb = $this->request['tb'];
        $id = $this->request['id'][0];
        $geometry = $this->request['coords'];

        try {
            if (!\utils::canUser('add_new')) {
                throw new \Exception('User has not enough privilege to add a new record');
            }

            $record = new Record($tb, $id, $this->db, $this->cfg);

            $record->setPlugin($this->prefix . 'geodata', [
              "id:addnew" => ["geometry" => $geometry]
            ]);

            $record->persist();
            $this->response('ok_insert_geodata', 'success', null, ['id' => $record->getCore('id')]);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->response('error_insert_geodata', 'error');
        }
    }


    /**
     * Erases geometry from geodata table
     *
     * @deprecated v5 — replaced by eraseGeometry()
     * @throws \Exception
     */
    public function erase()
    {
        $id_arr = $this->post['ids'];

        try {
            if (!\utils::canUser('edit')) {
                throw new \Exception('User has not enough privilege to edit records');
            }

            foreach ($id_arr as $id) {
                $del = $this->db->query(
                    'DELETE FROM ' . $this->prefix . 'geodata WHERE id = ?',
                    [$id],
                    'boolean'
                );
                if (!$del) {
                    $error = true;
                }
            }
            if (!$error) {
                $this->response('ok_delete_geodata', 'success');
            } else {
                throw new \Exception('Delete geodata query returned false');
            }
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->response('error_delete_geodata', 'error');
        }
    }

    /**
     * Updates geometry info in geodata table
     *
     * @deprecated v5 — replaced by updateGeometry()
     * @throws \Exception
     */
    public function update()
    {
        $post = $this->post['geodata'];

        try {
            if (!\utils::canUser('edit')) {
                throw new \Exception('User has not enough privilege to edit records');
            }

            foreach ($post as $row) {
                $ret = $this->db->query(
                    'UPDATE ' . $this->prefix . 'geodata SET geometry = ? WHERE id = ?',
                    [$row['coords'], $row['id']],
                    'boolean'
                );
                if (!$ret) {
                    $error = true;
                }
            }

            if (!$error) {
                $this->response('ok_update_geometry', 'success');
            } else {
                throw new \Exception('Update geometry query returned false');
            }
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->response('error_update_geometry', 'error');
        }
    }


    /**
     * Returns complex object with metadata and geodata to use for mapping
     *
     * @deprecated v5 — replaced by getGeoJson()
     */
    public function getGeoJson_v4()
    {
        $tb = $this->request['tb'];
        $obj_encoded = $this->request['obj_encoded'];

        try {
            list($where, $values) = $obj_encoded ? \SQL\SafeQuery::decode($obj_encoded) : ['1=1', []];

            $pref_preview_flds = \pref::get('preview');
            $preview = $pref_preview_flds[$tb] ?? $this->cfg->get("tables.$tb.preview");

            $part = [
              "{$tb}.id  AS id"
            ];

            foreach ($preview as $fldid) {
                if ($fldid != 'id') {
                    array_push($part, $tb . '.' . $fldid . ' AS "' . $this->cfg->get("tables.$tb.fields.$fldid.label") . '"');
                }
            }

            if (!preg_match('/' . $tb . '\.id/', $where) && $where) {
                $where = str_replace('id', $tb . '.id', $where);
            }
            array_push($part, $this->prefix . 'geodata.id AS geo_id');
            array_push($part, 'geometry');

            $sql = "SELECT " . implode(', ', $part)
              . " FROM  $tb LEFT JOIN " . $this->prefix . 'geodata '
              . " ON {$tb}.id = " . $this->prefix . 'geodata.id_link AND ' . $this->prefix . "geodata.table_link = '{$tb}' "
              . ' WHERE geometry IS NOT NULL '
              . ($where ? ' AND ' . $where : '');

            $res = $this->db->query($sql, $values);

            if ($res) {
                $response['status'] = 'success';
                $response['data'] = \utils::multiArray2GeoJSON($tb, $res);
            } elseif (!$res and (trim($where) === '1=1' || !$where) && \utils::canUser('add_new')) {
                $response['status'] = 'warning';
                $response['data'] = '';
            } else {
                $this->response('no_geodata_available', 'error');
                return;
            }

            // Get information from geodata/index.json
            $custom_layers = [];
            if (file_exists(PROJ_DIR . 'geodata/index.json')) {
                $custom_layers = json_decode(file_get_contents(PROJ_DIR . 'geodata/index.json'), true);
            }

            $response['metadata'] = [
              'tb_id'      =>  $tb,
              'tb'      =>  $this->cfg->get("tables.$tb.label"),
              'gmapskey'    =>  $this->cfg->get("main.gmapskey"),
              'canUserEdit'   => \utils::canUser('edit'),
              'custom_layers' => $custom_layers,
              'baseLocalPath' => PROJ_DIR . 'geodata/'
            ];

            echo $this->returnJson($response);
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->response('error_getting_geodata', 'error');
        }
    }
}
