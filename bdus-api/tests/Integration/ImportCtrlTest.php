<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for import_ctrl v5 endpoints:
 *   getTableFields(), importData(), importGeoJson(), importPhotos()
 *
 * Notes:
 * - $tb values use the full prefixed table name (e.g. "items") — the
 *   same format the frontend sends after calling listTables().
 * - The base BdusTestCase already creates files and file_links,
 *   so this class does NOT override createSchema().
 * - File-upload methods (previewFile, previewPhotos) are exercised indirectly:
 *   we plant the temp file the controller would create after move_uploaded_file,
 *   then call import* directly using the temp_id.
 * - Photo import tests require ext-zip; they are skipped when unavailable.
 */
class ImportCtrlTest extends BdusTestCase
{
    private const TB = 'items';

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Creates a temp file with $content as if previewFile() already stored it.
     * Returns the temp_id to pass directly to importData() / importGeoJson().
     */
    private function plantTempFile(string $content, string $suffix = ''): string
    {
        $tempId   = bin2hex(random_bytes(8));
        $tempPath = sys_get_temp_dir() . '/bdus_import_' . $tempId . $suffix;
        file_put_contents($tempPath, $content);
        return $tempId;
    }

    // ── getTableFields ────────────────────────────────────────────────────────

    public function testGetTableFieldsReturnsFieldList(): void
    {
        $ctrl = $this->makeController('import_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableFields');

        $this->assertSame('success', $res['status']);
        $this->assertIsArray($res['fields']);
        $this->assertNotEmpty($res['fields']);
        $first = $res['fields'][0];
        $this->assertArrayHasKey('name',  $first);
        $this->assertArrayHasKey('label', $first);
        $this->assertArrayHasKey('type',  $first);
    }

    public function testGetTableFieldsMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('import_ctrl', []);
        $res  = $this->callController($ctrl, 'getTableFields');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetTableFieldsUnknownTableReturnsEmptyList(): void
    {
        $ctrl = $this->makeController('import_ctrl', ['tb' => 'nonexistent_table']);
        $res  = $this->callController($ctrl, 'getTableFields');

        $this->assertSame('success', $res['status']);
        $this->assertSame([], $res['fields']);
    }

    // ── importData (CSV) ──────────────────────────────────────────────────────

    public function testImportDataCsvInsertsNewRows(): void
    {
        $csv    = "name,description\nImport Item A,Desc A\nImport Item B,Desc B\n";
        $tempId = $this->plantTempFile($csv);

        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => $tempId,
            'type'      => 'csv',
            'tb'        => self::TB,
            'mapping'   => ['name' => 'name', 'description' => 'description'],
            'key_field' => 'name',
        ]);
        $res = $this->callController($ctrl, 'importData');

        $this->assertSame('success', $res['status'], $res['code'] ?? '');
        $this->assertSame('ok_import_data', $res['code']);
        $this->assertSame(2, $res['inserted']);
        $this->assertSame(0, $res['updated']);
        $this->assertSame(2, $res['total']);
    }

    public function testImportDataCsvUpsertUpdatesExistingRow(): void
    {
        // Ensure "Import Item A" exists (may have been inserted by the previous test).
        $existing = static::$db->query(
            "SELECT id FROM items WHERE name = 'Import Item A'", [], 'read'
        );
        if (empty($existing)) {
            static::$db->execInTransaction(
                "INSERT INTO items (name, description) VALUES ('Import Item A', 'Old')"
            );
        }

        $csv    = "name,description\nImport Item A,Updated Desc\n";
        $tempId = $this->plantTempFile($csv);

        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => $tempId,
            'type'      => 'csv',
            'tb'        => self::TB,
            'mapping'   => ['name' => 'name', 'description' => 'description'],
            'key_field' => 'name',
        ]);
        $res = $this->callController($ctrl, 'importData');

        $this->assertSame('success', $res['status'], $res['code'] ?? '');
        $this->assertSame(0, $res['inserted']);
        $this->assertSame(1, $res['updated']);

        $row = static::$db->query(
            "SELECT description FROM items WHERE name = 'Import Item A'", [], 'read'
        );
        $this->assertSame('Updated Desc', $row[0]['description']);
    }

    public function testImportDataMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('import_ctrl', [], ['temp_id' => 'x']);
        $res  = $this->callController($ctrl, 'importData');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testImportDataMissingTempFileReturnsError(): void
    {
        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => 'does_not_exist_xyz',
            'type'      => 'csv',
            'tb'        => self::TB,
            'mapping'   => ['name' => 'name'],
            'key_field' => 'name',
        ]);
        $res = $this->callController($ctrl, 'importData');

        $this->assertSame('error', $res['status']);
        $this->assertSame('import_error_no_file', $res['code']);
    }

    public function testImportDataKeyFieldNotMappedReturnsError(): void
    {
        $csv    = "name,description\nSome Item,Desc\n";
        $tempId = $this->plantTempFile($csv);

        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => $tempId,
            'type'      => 'csv',
            'tb'        => self::TB,
            'mapping'   => ['name' => 'name'],
            'key_field' => 'description', // not in mapping values
        ]);
        $res = $this->callController($ctrl, 'importData');

        $this->assertSame('error', $res['status']);
        $this->assertSame('import_error_no_key', $res['code']);
    }

    public function testImportDataHandlesSemicolonDelimiter(): void
    {
        $csv    = "name;description\nSemicolon Item;Semicolon Desc\n";
        $tempId = $this->plantTempFile($csv);

        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => $tempId,
            'type'      => 'csv',
            'tb'        => self::TB,
            'mapping'   => ['name' => 'name', 'description' => 'description'],
            'key_field' => 'name',
        ]);
        $res = $this->callController($ctrl, 'importData');

        $this->assertSame('success', $res['status'], $res['code'] ?? '');
        $this->assertSame(1, $res['inserted']);
    }

    // ── importData (JSON) ─────────────────────────────────────────────────────

    public function testImportDataJsonInsertsNewRow(): void
    {
        $json   = json_encode([['name' => 'JSON Import Item', 'description' => 'From JSON']]);
        $tempId = $this->plantTempFile($json);

        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => $tempId,
            'type'      => 'json',
            'tb'        => self::TB,
            'mapping'   => ['name' => 'name', 'description' => 'description'],
            'key_field' => 'name',
        ]);
        $res = $this->callController($ctrl, 'importData');

        $this->assertSame('success', $res['status'], $res['code'] ?? '');
        $this->assertSame(1, $res['inserted']);
    }

    public function testImportDataJsonEnvelopeFormat(): void
    {
        $json   = json_encode(['data' => [['name' => 'JSON Envelope Item', 'description' => 'Envelope']]]);
        $tempId = $this->plantTempFile($json);

        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => $tempId,
            'type'      => 'json',
            'tb'        => self::TB,
            'mapping'   => ['name' => 'name', 'description' => 'description'],
            'key_field' => 'name',
        ]);
        $res = $this->callController($ctrl, 'importData');

        $this->assertSame('success', $res['status'], $res['code'] ?? '');
        $this->assertSame(1, $res['inserted']);
    }

    // ── importGeoJson ─────────────────────────────────────────────────────────

    public function testImportGeoJsonMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id' => 'x',
            'tb'      => self::TB,
            // missing geo_prop and key_field
        ]);
        $res = $this->callController($ctrl, 'importGeoJson');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testImportGeoJsonWorksForAnyTable(): void
    {
        // In v5 geodata is stored in bdus_geodata (table_link, id_link, geometry),
        // so ANY user table can receive GeoJSON regardless of its field config.
        // An empty FeatureCollection should succeed with 0 rows imported.
        $geojson = json_encode(['type' => 'FeatureCollection', 'features' => []]);
        $tempId  = $this->plantTempFile($geojson);

        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => $tempId,
            'tb'        => 'tags',
            'geo_prop'  => 'id',
            'key_field' => 'id',
        ]);
        $res = $this->callController($ctrl, 'importGeoJson');

        $this->assertSame('success', $res['status']);
    }

    public function testImportGeoJsonEmptyCollectionSucceeds(): void
    {
        $geojson = json_encode(['type' => 'FeatureCollection', 'features' => []]);
        $tempId  = $this->plantTempFile($geojson);

        // items has geo_data with type=geodata in the fixture config
        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => $tempId,
            'tb'        => self::TB,
            'geo_prop'  => 'id',
            'key_field' => 'id',
        ]);
        $res = $this->callController($ctrl, 'importGeoJson');

        $this->assertSame('success', $res['status'], $res['code'] ?? '');
        $this->assertSame(0, $res['updated']);
        $this->assertSame(0, $res['total']);
    }

    public function testImportGeoJsonNotFoundFeaturesCountedCorrectly(): void
    {
        $geojson = json_encode([
            'type'     => 'FeatureCollection',
            'features' => [
                [
                    'type'       => 'Feature',
                    'geometry'   => ['type' => 'Point', 'coordinates' => [12.5, 41.9]],
                    'properties' => ['name' => '__no_such_name__99999'],
                ],
            ],
        ]);
        $tempId = $this->plantTempFile($geojson);

        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => $tempId,
            'tb'        => self::TB,
            'geo_prop'  => 'name',
            'key_field' => 'name',
        ]);
        $res = $this->callController($ctrl, 'importGeoJson');

        $this->assertSame('success', $res['status'], $res['code'] ?? '');
        $this->assertSame(0, $res['updated']);
        $this->assertSame(1, $res['not_found']);
        $this->assertSame(1, $res['total']);
    }

    // ── importPhotos ──────────────────────────────────────────────────────────

    public function testImportPhotosMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('import_ctrl', [], []);
        $res  = $this->callController($ctrl, 'importPhotos');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testImportPhotosMissingTempFilesReturnsError(): void
    {
        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id' => 'totally_missing_id',
            'tb'      => self::TB,
        ]);
        $res = $this->callController($ctrl, 'importPhotos');

        $this->assertSame('error', $res['status']);
        $this->assertSame('import_error_no_file', $res['code']);
    }

    public function testImportPhotosLinksFilesToRecords(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ext-zip not available');
        }

        if (!defined('PROJ_DIR')) {
            define('PROJ_DIR', sys_get_temp_dir() . '/bdus_test_proj/');
        }
        @mkdir(constant('PROJ_DIR') . 'files/', 0755, true);

        // Pick an existing record
        $rows = static::$db->query("SELECT id FROM items LIMIT 1", [], 'read');
        $this->assertNotEmpty($rows, 'Need at least one item record');
        $recordId = $rows[0]['id'];

        // Build a ZIP in memory
        $tempZipFile = sys_get_temp_dir() . '/bdus_build_test.zip';
        $zip = new \ZipArchive();
        $zip->open($tempZipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('photo1.jpg', 'fake_image_bytes');
        $zip->close();

        // Plant temp files as if previewPhotos() already stored them
        $tempId = bin2hex(random_bytes(8));
        copy($tempZipFile, sys_get_temp_dir() . '/bdus_import_' . $tempId . '.zip');
        file_put_contents(
            sys_get_temp_dir() . '/bdus_import_' . $tempId . '.csv',
            "filename,record_id\nphoto1.jpg,{$recordId}\n"
        );
        @unlink($tempZipFile);

        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id' => $tempId,
            'tb'      => self::TB,
        ]);
        $res = $this->callController($ctrl, 'importPhotos');

        $this->assertSame('success', $res['status'], $res['code'] ?? '');
        $this->assertSame(1, $res['linked']);
        $this->assertSame(0, $res['not_found']);

        $fileRows = static::$db->query(
            "SELECT id FROM bdus_files WHERE creator = 'import'", [], 'read'
        );
        $this->assertNotEmpty($fileRows);

        $linkRows = static::$db->query(
            "SELECT * FROM bdus_file_links WHERE table_name = ? AND record_id = ?",
            [self::TB, $recordId],
            'read'
        );
        $this->assertNotEmpty($linkRows);
    }

    // ── Privilege checks ──────────────────────────────────────────────────────

    public function testImportDataRequiresEditPrivilege(): void
    {
        $this->setPrivilege(99);

        $ctrl = $this->makeController('import_ctrl', [], [
            'temp_id'   => 'x',
            'type'      => 'csv',
            'tb'        => self::TB,
            'mapping'   => ['name' => 'name'],
            'key_field' => 'name',
        ]);
        $res = $this->callController($ctrl, 'importData');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }

    public function testGetTableFieldsRequiresReadPrivilege(): void
    {
        $this->setPrivilege(99);

        $ctrl = $this->makeController('import_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTableFields');
        $this->assertSame('not_enough_privilege', $res['code']);

        $this->setPrivilege(1);
    }
}
