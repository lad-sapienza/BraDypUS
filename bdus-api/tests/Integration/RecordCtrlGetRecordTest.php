<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for record_ctrl::getRecord() and ::getFieldOptions().
 */
class RecordCtrlGetRecordTest extends BdusTestCase
{
    private const TB = 'test__items';

    // ── getRecord ─────────────────────────────────────────────────

    public function testGetRecordReturnsExpectedTopLevelKeys(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');

        foreach (['metadata', 'schema', 'core', 'plugins', 'links', 'backlinks', 'manualLinks', 'files', 'geodata', 'rs'] as $key) {
            $this->assertArrayHasKey($key, $res, "Missing top-level key: $key");
        }
    }

    public function testGetRecordMetadataShape(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');
        $meta = $res['metadata'];

        $this->assertSame(self::TB, $meta['tb_id']);
        $this->assertSame('items',  $meta['tb_stripped']);
        $this->assertSame('Items',  $meta['tb_label']);
        $this->assertSame(1,        $meta['rec_id']);
        $this->assertIsBool($meta['can_edit']);
        $this->assertIsBool($meta['can_delete']);
    }

    public function testGetRecordCoreHasCorrectFields(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');
        $core = $res['core'];

        foreach (['id', 'name', 'description', 'status'] as $fld) {
            $this->assertArrayHasKey($fld, $core, "Missing core field: $fld");
            $this->assertArrayHasKey('val', $core[$fld]);
        }
        $this->assertSame('Alpha item', $core['name']['val']);
        $this->assertSame('active',     $core['status']['val']);
    }

    public function testGetRecordSchemaContainsFields(): void
    {
        $ctrl   = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1]);
        $res    = $this->callController($ctrl, 'getRecord');
        $fields = $res['schema']['fields'];

        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);

        $byName = array_column($fields, null, 'name');
        $this->assertArrayHasKey('name',        $byName);
        $this->assertArrayHasKey('description', $byName);
        $this->assertArrayHasKey('status',      $byName);

        // Each field must have required schema keys
        foreach ($fields as $f) {
            foreach (['name', 'label', 'type', 'readonly', 'disabled', 'hide', 'required'] as $k) {
                $this->assertArrayHasKey($k, $f, "Field schema missing key: $k");
            }
        }
    }

    public function testGetRecordSchemaFieldTypesAreCorrect(): void
    {
        $ctrl   = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1]);
        $res    = $this->callController($ctrl, 'getRecord');
        $byName = array_column($res['schema']['fields'], null, 'name');

        $this->assertSame('text',      $byName['name']['type']);
        $this->assertSame('long_text', $byName['description']['type']);
        $this->assertSame('select',    $byName['status']['type']);
        $this->assertTrue($byName['id']['readonly']);
        $this->assertTrue($byName['creator']['hide']);
    }

    public function testGetRecordAddNewReturnsNullId(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB /* no id */]);
        $res  = $this->callController($ctrl, 'getRecord');

        $this->assertNull($res['metadata']['rec_id']);
        $this->assertIsArray($res['schema']['fields']);
        // Core values should be null for a new record
        $this->assertNull($res['core']['name']['val']);
    }

    public function testGetRecordMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', []);
        $res  = $this->callController($ctrl, 'getRecord');
        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── manualLinks ───────────────────────────────────────────────

    public function testGetRecordManualLinksShapeForItemRecord(): void
    {
        // The seed inserts a manual link between items 1 and 2 in test__userlinks
        // (record-to-record link, NOT a file link — those live in test__file_links).
        // Viewing item 1 should expose item 2 in manualLinks.
        $ctrl = $this->makeController('record_ctrl', ['tb' => 'test__items', 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');

        $this->assertArrayHasKey('manualLinks', $res);
        $this->assertNotEmpty($res['manualLinks'], 'Expected at least one manual link for item 1');

        $link = array_values($res['manualLinks'])[0];
        foreach (['key', 'tb_id', 'tb_stripped', 'ref_id', 'ref_label'] as $k) {
            $this->assertArrayHasKey($k, $link, "manualLinks entry missing key: $k");
        }
        $this->assertSame('test__items', $link['tb_id']);
        $this->assertSame('items',       $link['tb_stripped']);
        $this->assertSame(2,             $link['ref_id']);
    }

    // ── files enrichment ─────────────────────────────────────────

    public function testGetRecordFilesAreEnrichedWithUrlAndIsImage(): void
    {
        $ctrl  = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1]);
        $res   = $this->callController($ctrl, 'getRecord');
        $files = $res['files'];

        $this->assertIsArray($files);
        $this->assertCount(2, $files, 'Expected 2 files linked to item 1');

        foreach ($files as $f) {
            // url is no longer returned by the backend: the Vue frontend
            // constructs it client-side from auth.user.app + file fields.
            $this->assertArrayNotHasKey('url', $f, 'url should not be in file response (built client-side)');
            $this->assertArrayHasKey('is_image', $f, 'File missing is_image key');
            $this->assertArrayHasKey('filename', $f, 'File missing filename key');
            $this->assertIsBool($f['is_image']);
        }

        // Identify by ext
        $byExt = array_column($files, null, 'ext');
        $this->assertTrue($byExt['jpg']['is_image'],  'jpg should be flagged as image');
        $this->assertFalse($byExt['pdf']['is_image'], 'pdf should NOT be flagged as image');

        // url is built client-side; verify the raw fields needed to construct it are present
        $this->assertArrayHasKey('id',  $byExt['jpg']);
        $this->assertArrayHasKey('ext', $byExt['jpg']);
    }

    // ── getFieldOptions ───────────────────────────────────────────

    public function testGetFieldOptionsStaticDic(): void
    {
        // The 'status' field in test__items is type=select with no source configured
        // → should return empty array (no dic/vocabulary/get_values_from_tb set)
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'fld' => 'status']);
        $res  = $this->callController($ctrl, 'getFieldOptions');
        $this->assertIsArray($res);
    }

    public function testGetFieldOptionsMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB /* no fld */]);
        $res  = $this->callController($ctrl, 'getFieldOptions');
        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── Template loading ──────────────────────────────────────────

    public function testGetRecordWithValidTemplateIncludesSchemaTemplate(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1, 'template' => 'default']);
        $res  = $this->callController($ctrl, 'getRecord');

        $this->assertArrayHasKey('schema', $res);
        $this->assertArrayHasKey('template', $res['schema'], 'schema.template key missing');
        $this->assertNotNull($res['schema']['template'], 'schema.template should not be null for a valid template');
        $this->assertNull($res['schema']['template_errors'], 'schema.template_errors should be null for a valid template');
        $this->assertArrayHasKey('sections', $res['schema']['template']);
    }

    public function testGetRecordWithInvalidTemplateNameReturnsTemplateErrors(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1, 'template' => 'nonexistent']);
        $res  = $this->callController($ctrl, 'getRecord');

        $this->assertArrayHasKey('schema', $res);
        $this->assertNull($res['schema']['template'], 'schema.template should be null for a missing template');
        $this->assertIsArray($res['schema']['template_errors']);
        $this->assertContains('template_not_found', $res['schema']['template_errors']);
    }

    public function testGetTemplatesReturnsAvailableNames(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTemplates');

        $this->assertArrayHasKey('templates', $res);
        $this->assertIsArray($res['templates']);
        $this->assertContains('default', $res['templates']);
    }
}
