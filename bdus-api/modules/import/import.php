<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Import module — batch-import CSV, JSON, GeoJSON and photo ZIPs.
 *
 * Two-step workflow (avoids double upload):
 *   Step 1 — previewFile() / previewPhotos()
 *             Uploads file(s) to a server-side temp location, returns
 *             a temp_id plus preview data (columns, rows, geo props, …).
 *   Step 2 — importData() / importGeoJson() / importPhotos()
 *             Receives temp_id, runs the atomic import and cleans up.
 *
 * Additional utility:
 *   getTableFields() — returns field list for a given table (used by the
 *                      Vue mapping UI to populate dropdowns).
 *
 * All mutating endpoints require edit privilege.
 * getTableFields() requires read privilege.
 */

class import_ctrl extends Controller
{
    // ── Utility ───────────────────────────────────────────────────────────────

    /**
     * GET ?tb=<table>
     * Returns the list of fields for a table (name, label, type).
     * Used by the Vue mapping step to populate column → field dropdowns.
     *
     * Response: { status: 'success', fields: [{name, label, type}, …] }
     */
    public function getTableFields(): void
    {
        if (!\Auth\Authorization::can('read')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $tb = $this->get['tb'] ?? null;
        if (!$tb) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'tb']);
            return;
        }

        $fields = $this->cfg->get("tables.{$tb}.fields") ?: [];
        $result = array_values(array_map(fn(array $f): array => [
            'name'  => $f['name'],
            'label' => $f['label'] ?? $f['name'],
            'type'  => $f['type']  ?? 'text',
        ], $fields));

        $this->returnJson(['status' => 'success', 'fields' => $result]);
    }

    // ── Step 1: upload + preview ──────────────────────────────────────────────

    /**
     * POST multipart: file, type (csv|json|geojson), [tb] (required for geojson)
     *
     * CSV/JSON response:
     *   { status, temp_id, columns: string[], rows: any[][], count: int }
     *
     * GeoJSON response:
     *   { status, temp_id, count, geometry_types: string[],
     *     geo_props: string[], geo_field: string|null }
     */
    public function previewFile(): void
    {
        if (!\Auth\Authorization::can('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $type = $this->post['type'] ?? null;
        $tb   = $this->post['tb']   ?? null;

        if (!$type || !isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->returnJson(['status' => 'error', 'code' => 'import_error_no_file']);
            return;
        }

        $tempId   = bin2hex(random_bytes(8));
        $tempPath = sys_get_temp_dir() . '/bdus_import_' . $tempId;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $tempPath)) {
            $this->returnJson(['status' => 'error', 'code' => 'import_error_file']);
            return;
        }

        try {
            if ($type === 'csv') {
                [$columns, $rows, $count] = $this->previewCsvFile($tempPath);
                $this->returnJson([
                    'status'  => 'success',
                    'temp_id' => $tempId,
                    'columns' => $columns,
                    'rows'    => $rows,
                    'count'   => $count,
                ]);

            } elseif ($type === 'json') {
                [$columns, $rows, $count] = $this->previewJsonFile($tempPath);
                $this->returnJson([
                    'status'  => 'success',
                    'temp_id' => $tempId,
                    'columns' => $columns,
                    'rows'    => $rows,
                    'count'   => $count,
                ]);

            } elseif ($type === 'geojson') {
                [$count, $geoTypes, $geoProps] = $this->previewGeoJsonFile($tempPath);
                $this->returnJson([
                    'status'         => 'success',
                    'temp_id'        => $tempId,
                    'count'          => $count,
                    'geometry_types' => $geoTypes,
                    'geo_props'      => $geoProps,
                ]);

            } else {
                @unlink($tempPath);
                $this->returnJson(['status' => 'error', 'code' => 'import_error_unknown_type']);
            }

        } catch (\Throwable $e) {
            @unlink($tempPath);
            $this->log->error($e);
            $this->returnJson([
                'status' => 'error',
                'code'   => 'import_error_parse',
                'detail' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST multipart: zip, index (CSV with filename,record_id columns), tb
     *
     * Response:
     *   { status, temp_id, index_rows: [{filename,record_id}…],
     *     missing_files: string[], total: int }
     */
    public function previewPhotos(): void
    {
        if (!\Auth\Authorization::can('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        if (
            !isset($_FILES['zip'])   || $_FILES['zip']['error']   !== UPLOAD_ERR_OK ||
            !isset($_FILES['index']) || $_FILES['index']['error']  !== UPLOAD_ERR_OK
        ) {
            $this->returnJson(['status' => 'error', 'code' => 'import_error_no_file']);
            return;
        }

        $tempId  = bin2hex(random_bytes(8));
        $zipPath = sys_get_temp_dir() . '/bdus_import_' . $tempId . '.zip';
        $idxPath = sys_get_temp_dir() . '/bdus_import_' . $tempId . '.csv';

        if (
            !move_uploaded_file($_FILES['zip']['tmp_name'],   $zipPath) ||
            !move_uploaded_file($_FILES['index']['tmp_name'], $idxPath)
        ) {
            $this->returnJson(['status' => 'error', 'code' => 'import_error_file']);
            return;
        }

        try {
            $index = $this->parseCsvIndex($idxPath);

            // Collect filenames present in the ZIP
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('import_error_zip');
            }
            $zipFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $zipFiles[] = basename($zip->getNameIndex($i));
            }
            $zip->close();

            $missing = [];
            foreach ($index as $row) {
                if ($row['filename'] && !in_array($row['filename'], $zipFiles, true)) {
                    $missing[] = $row['filename'];
                }
            }

            $this->returnJson([
                'status'        => 'success',
                'temp_id'       => $tempId,
                'index_rows'    => array_slice($index, 0, 10),
                'missing_files' => $missing,
                'total'         => count($index),
            ]);

        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->returnJson([
                'status' => 'error',
                'code'   => $e->getMessage() ?: 'import_error_file',
            ]);
        }
    }

    // ── Step 2: run import ────────────────────────────────────────────────────

    /**
     * POST JSON: { temp_id, type (csv|json), tb, mapping: {fileCol: tableField},
     *              key_field }
     *
     * Upserts all rows inside a single transaction.
     * On any error the transaction is rolled back and the error row is reported.
     *
     * Response: { status, code, inserted, updated, total }
     */
    public function importData(): void
    {
        if (!\Auth\Authorization::can('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $tempId   = $this->post['temp_id']   ?? null;
        $type     = $this->post['type']      ?? 'csv';
        $tb       = $this->post['tb']        ?? null;
        $mapping  = $this->post['mapping']   ?? [];   // {fileCol: tableField}
        $keyField = $this->post['key_field'] ?? null; // table field used as upsert key

        if (!$tempId || !$tb || !$keyField || empty($mapping)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $tempPath = sys_get_temp_dir() . '/bdus_import_' . $tempId;
        if (!file_exists($tempPath)) {
            $this->returnJson(['status' => 'error', 'code' => 'import_error_no_file']);
            return;
        }

        try {
            $rows = ($type === 'json')
                ? $this->readJsonRows($tempPath)
                : $this->readCsvRows($tempPath);
        } catch (\Throwable $e) {
            @unlink($tempPath);
            $this->returnJson(['status' => 'error', 'code' => 'import_error_parse', 'detail' => $e->getMessage()]);
            return;
        }

        // Verify the key_field is covered by the mapping
        $keyFileCol = array_search($keyField, $mapping, true);
        if ($keyFileCol === false) {
            @unlink($tempPath);
            $this->returnJson(['status' => 'error', 'code' => 'import_error_no_key']);
            return;
        }

        $inserted = 0;
        $updated  = 0;

        try {
            $this->db->beginTransaction();

            foreach ($rows as $rowNum => $row) {
                $keyValue = $row[$keyFileCol] ?? null;
                if ($keyValue === null || $keyValue === '') continue;

                // Build mapped data (skip columns mapped to nothing)
                $data = [];
                foreach ($mapping as $fileCol => $tableField) {
                    if ($tableField && array_key_exists($fileCol, $row)) {
                        $data[$tableField] = $row[$fileCol];
                    }
                }
                if (empty($data)) continue;

                $existing = $this->db->query(
                    "SELECT id FROM {$tb} WHERE {$keyField} = ?",
                    [$keyValue],
                    'read'
                );

                if ($existing) {
                    $id = $existing[0]['id'];
                    $updateData = $data;
                    unset($updateData[$keyField]); // never overwrite the match key
                    if (empty($updateData)) { $updated++; continue; }

                    $sets = implode(', ', array_map(fn($f) => "{$f} = ?", array_keys($updateData)));
                    $vals = array_values($updateData);
                    $vals[] = $id;
                    $this->db->query("UPDATE {$tb} SET {$sets} WHERE id = ?", $vals, 'boolean');
                    $updated++;
                } else {
                    // Always set creator to the authenticated user
                    $data['creator'] = \Auth\CurrentUser::id() ?: 0;
                    $cols = implode(', ', array_keys($data));
                    $phs  = implode(', ', array_fill(0, count($data), '?'));
                    $this->db->query(
                        "INSERT INTO {$tb} ({$cols}) VALUES ({$phs})",
                        array_values($data),
                        'id'
                    );
                    $inserted++;
                }
            }

            $this->db->commit();
            @unlink($tempPath);

            $this->returnJson([
                'status'   => 'success',
                'code'     => 'ok_import_data',
                'inserted' => $inserted,
                'updated'  => $updated,
                'total'    => $inserted + $updated,
            ]);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            @unlink($tempPath);
            $this->log->error($e);
            $this->returnJson([
                'status' => 'error',
                'code'   => 'import_error_transaction',
                'detail' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST JSON: { temp_id, tb, geo_prop, key_field }
     *
     * For each GeoJSON feature:
     *   1. Look up record by feature.properties[geo_prop] == table.key_field
     *   2. Upsert geometry into bdus_geodata (table_link=tb, id_link=record.id)
     *
     * Geodata is stored in the bdus_geodata system table, not as a column in
     * the user table. Upsert: UPDATE if a row already exists, INSERT otherwise.
     *
     * Atomic — any error triggers rollback.
     *
     * Response: { status, code, updated, not_found, total }
     */
    public function importGeoJson(): void
    {
        if (!\Auth\Authorization::can('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $tempId   = $this->post['temp_id']   ?? null;
        $tb       = $this->post['tb']        ?? null;
        $geoProp  = $this->post['geo_prop']  ?? null; // GeoJSON property used as key
        $keyField = $this->post['key_field'] ?? null; // matching table field

        if (!$tempId || !$tb || !$geoProp || !$keyField) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $tempPath = sys_get_temp_dir() . '/bdus_import_' . $tempId;
        if (!file_exists($tempPath)) {
            $this->returnJson(['status' => 'error', 'code' => 'import_error_no_file']);
            return;
        }

        $geojson = json_decode(file_get_contents($tempPath), true);
        if (!$geojson || ($geojson['type'] ?? '') !== 'FeatureCollection') {
            @unlink($tempPath);
            $this->returnJson(['status' => 'error', 'code' => 'import_error_parse']);
            return;
        }

        $updated  = 0;
        $notFound = 0;

        try {
            $this->db->beginTransaction();

            foreach ($geojson['features'] as $feature) {
                $keyValue = $feature['properties'][$geoProp] ?? null;
                if ($keyValue === null) { $notFound++; continue; }

                $existing = $this->db->query(
                    "SELECT id FROM {$tb} WHERE {$keyField} = ?",
                    [$keyValue],
                    'read'
                );
                if (!$existing) { $notFound++; continue; }

                $recordId = (int) $existing[0]['id'];

                $wkt = \geoPHP\geoPHP::load(
                    json_encode($feature['geometry']),
                    'geojson'
                )->out('wkt');

                // Upsert into bdus_geodata
                $geoRow = $this->db->query(
                    "SELECT id FROM bdus_geodata WHERE table_link = ? AND id_link = ?",
                    [$tb, $recordId],
                    'read'
                );

                if ($geoRow) {
                    $this->db->query(
                        "UPDATE bdus_geodata SET geometry = ? WHERE id = ?",
                        [$wkt, (int) $geoRow[0]['id']],
                        'boolean'
                    );
                } else {
                    $this->db->query(
                        "INSERT INTO bdus_geodata (table_link, id_link, geometry) VALUES (?, ?, ?)",
                        [$tb, $recordId, $wkt],
                        'id'
                    );
                }
                $updated++;
            }

            $this->db->commit();
            @unlink($tempPath);

            $this->returnJson([
                'status'    => 'success',
                'code'      => 'ok_import_geojson',
                'updated'   => $updated,
                'not_found' => $notFound,
                'total'     => count($geojson['features']),
            ]);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            @unlink($tempPath);
            $this->log->error($e);
            $this->returnJson([
                'status' => 'error',
                'code'   => 'import_error_transaction',
                'detail' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST JSON: { temp_id, tb }
     *
     * For each row in the index CSV:
     *   1. Locate photo in the ZIP by filename
     *   2. Copy to PROJ_DIR/files/ with a collision-safe name
     *   3. Insert into {prefix}files
     *   4. Insert into {prefix}file_links (table_name = tb, record_id)
     *
     * Atomic — any DB error triggers rollback (already-written files are left
     * in place but orphaned; a future cleanup pass can remove them).
     *
     * Response: { status, code, linked, not_found, total }
     */
    public function importPhotos(): void
    {
        if (!\Auth\Authorization::can('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $tempId = $this->post['temp_id'] ?? null;
        $tb     = $this->post['tb']      ?? null;

        if (!$tempId || !$tb) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        $zipPath = sys_get_temp_dir() . '/bdus_import_' . $tempId . '.zip';
        $idxPath = sys_get_temp_dir() . '/bdus_import_' . $tempId . '.csv';

        if (!file_exists($zipPath) || !file_exists($idxPath)) {
            $this->returnJson(['status' => 'error', 'code' => 'import_error_no_file']);
            return;
        }

        $filesDir = PROJ_DIR . 'files/';
        if (!is_dir($filesDir)) {
            @mkdir($filesDir, 0755, true);
        }

        $index  = $this->parseCsvIndex($idxPath);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->returnJson(['status' => 'error', 'code' => 'import_error_zip']);
            return;
        }

        $linked   = 0;
        $notFound = 0;

        try {
            $this->db->beginTransaction();

            foreach ($index as $row) {
                $filename = $row['filename'] ?? null;
                $recordId = isset($row['record_id']) ? (int) $row['record_id'] : null;

                if (!$filename || !$recordId) { $notFound++; continue; }

                // Locate file in ZIP (exact path first, then basename)
                $zipIdx = $zip->locateName($filename);
                if ($zipIdx === false) {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        if (basename($zip->getNameIndex($i)) === $filename) {
                            $zipIdx = $i;
                            break;
                        }
                    }
                }
                if ($zipIdx === false) { $notFound++; continue; }

                // Verify the target record exists
                $rec = $this->db->query(
                    "SELECT id FROM {$tb} WHERE id = ?",
                    [$recordId],
                    'read'
                );
                if (!$rec) { $notFound++; continue; }

                // Write file with a collision-safe name
                $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $baseName = pathinfo($filename, PATHINFO_FILENAME);
                $destName = $baseName . '_' . bin2hex(random_bytes(4));
                $destPath = $filesDir . $destName . '.' . $ext;

                file_put_contents($destPath, $zip->getFromIndex($zipIdx));

                // Insert into system files table
                $fileId = $this->db->query(
                    "INSERT INTO bdus_files (ext, filename, creator) VALUES (?, ?, ?)",
                    [$ext, $destName, 'import'],
                    'id'
                );
                if (!$fileId) { $notFound++; continue; }

                // Link file to record
                $this->db->query(
                    "INSERT INTO bdus_file_links (file_id, table_name, record_id, sort)"
                    . " VALUES (?, ?, ?, ?)",
                    [$fileId, $tb, $recordId, 0],
                    'boolean'
                );
                $linked++;
            }

            $this->db->commit();
            $zip->close();
            @unlink($zipPath);
            @unlink($idxPath);

            $this->returnJson([
                'status'    => 'success',
                'code'      => 'ok_import_photos',
                'linked'    => $linked,
                'not_found' => $notFound,
                'total'     => $linked + $notFound,
            ]);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $zip->close();
            $this->log->error($e);
            $this->returnJson([
                'status' => 'error',
                'code'   => 'import_error_transaction',
                'detail' => $e->getMessage(),
            ]);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Returns [columns, preview_rows, total_count] for a CSV file. */
    private function previewCsvFile(string $path): array
    {
        $rows    = $this->readCsvRows($path);
        $columns = $rows ? array_keys($rows[0]) : [];
        return [$columns, array_map('array_values', array_slice($rows, 0, 10)), count($rows)];
    }

    /** Returns [columns, preview_rows, total_count] for a JSON file. */
    private function previewJsonFile(string $path): array
    {
        $rows    = $this->readJsonRows($path);
        $columns = $rows ? array_keys($rows[0]) : [];
        return [$columns, array_map('array_values', array_slice($rows, 0, 10)), count($rows)];
    }

    /** Returns [feature_count, geometry_types[], property_names[]] for GeoJSON. */
    private function previewGeoJsonFile(string $path): array
    {
        $geojson = json_decode(file_get_contents($path), true);
        if (!$geojson) throw new \RuntimeException('import_error_parse');

        $features  = $geojson['features'] ?? [];
        $geoTypes  = array_values(array_unique(
            array_column(array_filter(array_column($features, 'geometry')), 'type')
        ));
        $geoProps  = [];
        foreach ($features as $f) {
            $geoProps = array_unique(array_merge($geoProps, array_keys($f['properties'] ?? [])));
        }
        return [count($features), $geoTypes, array_values($geoProps)];
    }

    /**
     * Reads a CSV into an array of associative rows.
     * Auto-detects delimiter (comma, semicolon, tab).
     */
    private function readCsvRows(string $path): array
    {
        $delim = $this->detectCsvDelimiter($path);
        $rows  = [];
        $fh    = fopen($path, 'r');
        if ($fh === false) throw new \RuntimeException('Cannot open file');

        $headers = null;
        while (($line = fgetcsv($fh, 0, $delim)) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $line);
                continue;
            }
            // Pad short rows to match header count
            while (count($line) < count($headers)) $line[] = '';
            $rows[] = array_combine($headers, array_slice($line, 0, count($headers)));
        }
        fclose($fh);
        return $rows;
    }

    /**
     * Reads a JSON file into an array of associative rows.
     * Supports: top-level array, or {"data": [...]} envelope.
     */
    private function readJsonRows(string $path): array
    {
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) throw new \RuntimeException('import_error_parse');
        return isset($data[0]) ? $data : ($data['data'] ?? []);
    }

    /**
     * Reads the photo index CSV.
     * Normalises column names: accepts 'filename'/'file' and 'record_id'/'id'.
     */
    private function parseCsvIndex(string $path): array
    {
        $rows = $this->readCsvRows($path);
        return array_map(function (array $r): array {
            $vals = array_values($r);
            return [
                'filename'  => $r['filename'] ?? $r['file'] ?? ($vals[0] ?? null),
                'record_id' => $r['record_id'] ?? $r['id']  ?? ($vals[1] ?? null),
            ];
        }, $rows);
    }

    /** Auto-detects the most common delimiter in the first line of a CSV. */
    private function detectCsvDelimiter(string $path): string
    {
        $fh        = fopen($path, 'r');
        $firstLine = fgets($fh);
        fclose($fh);
        $counts = [
            ','  => substr_count($firstLine, ','),
            ';'  => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($counts);
        return array_key_first($counts);
    }

}

