<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for templates_ctrl v5 JSON endpoints.
 *
 * Tests cover the six new JSON methods:
 *   getTableList, getTemplateList, getTemplate,
 *   saveTemplate, deleteTemplate, renameTemplate
 *
 * Fixture app:    bdus_test  (prefix )
 * Fixture tables: items (regular), tags (plugin)
 * Fixture fields: id, creator, name, description, status
 *
 * The test suite creates/deletes template files inside the fixture template
 * directory (tests/fixtures/bdus_test/template/). The existing fixture files
 * items.default.json and items.invalid.json are never modified.
 */
class TemplatesCtrlTest extends BdusTestCase
{
    private const TB      = 'items';
    private const STRIPPED = 'items';   // TB minus prefix ''
    private const TB_PLUG = 'tags';

    /** Name used for templates created/renamed during tests */
    private const TMP_NAME  = 'tmp_test';
    private const TMP_NAME2 = 'tmp_test_renamed';

    public static function tearDownAfterClass(): void
    {
        // Clean up any leftover test template files
        foreach ([self::TMP_NAME, self::TMP_NAME2] as $name) {
            $path = \Template\Loader::getPath('bdus_test', self::STRIPPED, $name);
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDownAfterClass();
    }

    // ── getTableList ─────────────────────────────────────────────────────

    public function testGetTableListReturnsSuccess(): void
    {
        $ctrl = $this->makeController('templates_ctrl');
        $res  = $this->callController($ctrl, 'getTableList');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('tables', $res);
        $this->assertIsArray($res['tables']);
    }

    public function testGetTableListExcludesPluginTables(): void
    {
        $ctrl = $this->makeController('templates_ctrl');
        $res  = $this->callController($ctrl, 'getTableList');

        $tbs = array_column($res['tables'], 'tb');
        $this->assertContains(self::TB, $tbs);
        $this->assertNotContains(self::TB_PLUG, $tbs);
    }

    public function testGetTableListItemsHaveRequiredKeys(): void
    {
        $ctrl = $this->makeController('templates_ctrl');
        $res  = $this->callController($ctrl, 'getTableList');

        $item = $res['tables'][0];
        foreach (['tb', 'label', 'stripped'] as $key) {
            $this->assertArrayHasKey($key, $item, "Missing key: $key");
        }
    }

    public function testGetTableListStrippedRemovesPrefix(): void
    {
        $ctrl = $this->makeController('templates_ctrl');
        $res  = $this->callController($ctrl, 'getTableList');

        $row = array_values(array_filter($res['tables'], fn($t) => $t['tb'] === self::TB))[0];
        $this->assertSame(self::STRIPPED, $row['stripped']);
    }

    public function testGetTableListRequiresSuperAdmin(): void
    {
        $this->setPrivilege(99);
        $ctrl = $this->makeController('templates_ctrl');
        $res  = $this->callController($ctrl, 'getTableList');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
        $this->assertSame('not_enough_privilege', $res['code']);
    }

    // ── getTemplateList ──────────────────────────────────────────────────

    public function testGetTemplateListReturnsSuccess(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTemplateList');

        $this->assertSame('success', $res['status']);
    }

    public function testGetTemplateListContainsFixtureTemplate(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTemplateList');

        // The fixture directory contains items.default.json
        $this->assertContains('default', $res['templates']);
    }

    public function testGetTemplateListHasFieldsAndPlugins(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTemplateList');

        $this->assertArrayHasKey('fields',  $res);
        $this->assertArrayHasKey('plugins', $res);
        $this->assertIsArray($res['fields']);
        $this->assertIsArray($res['plugins']);
    }

    public function testGetTemplateListFieldsContainFixtureFields(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTemplateList');

        $fieldNames = array_column($res['fields'], 'name');
        $this->assertContains('name', $fieldNames);
    }

    public function testGetTemplateListPluginsContainTagsTable(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTemplateList');

        $pluginTbs = array_column($res['plugins'], 'tb');
        $this->assertContains(self::TB_PLUG, $pluginTbs);
    }

    public function testGetTemplateListMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('templates_ctrl');
        $res  = $this->callController($ctrl, 'getTemplateList');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetTemplateListRequiresSuperAdmin(): void
    {
        $this->setPrivilege(99);
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTemplateList');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
    }

    // ── getTemplate ──────────────────────────────────────────────────────

    public function testGetTemplateReturnsFixtureTemplate(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB, 'name' => 'default']);
        $res  = $this->callController($ctrl, 'getTemplate');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('template', $res);
        $this->assertArrayHasKey('sections', $res['template']);
    }

    public function testGetTemplateHasSectionsWithContent(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB, 'name' => 'default']);
        $res  = $this->callController($ctrl, 'getTemplate');

        $this->assertNotEmpty($res['template']['sections']);
        $first = $res['template']['sections'][0];
        $this->assertArrayHasKey('content', $first);
    }

    public function testGetTemplateNotFoundReturnsError(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB, 'name' => 'no_such_template']);
        $res  = $this->callController($ctrl, 'getTemplate');

        $this->assertSame('error', $res['status']);
        $this->assertSame('template_not_found', $res['code']);
    }

    public function testGetTemplateMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getTemplate');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── saveTemplate ─────────────────────────────────────────────────────

    public function testSaveTemplateCreatesFile(): void
    {
        $payload = [
            'sections' => [
                [
                    'label'   => 'Main',
                    'content' => [
                        ['field' => 'name',   'width' => '1/2'],
                        ['field' => 'status', 'width' => '1/4'],
                    ],
                ],
            ],
        ];

        $ctrl = $this->makeController(
            'templates_ctrl',
            ['tb' => self::TB, 'name' => self::TMP_NAME],
            $payload
        );
        $res = $this->callController($ctrl, 'saveTemplate');

        $this->assertSame('success', $res['status']);
        $this->assertSame('template_saved', $res['code']);

        $path = \Template\Loader::getPath('bdus_test', self::STRIPPED, self::TMP_NAME);
        $this->assertFileExists($path);
    }

    public function testSaveTemplateFileContainsValidJson(): void
    {
        $path = \Template\Loader::getPath('bdus_test', self::STRIPPED, self::TMP_NAME);
        $this->assertFileExists($path);

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('sections', $decoded);
    }

    public function testSaveTemplateValidationRejectsUnknownField(): void
    {
        $payload = [
            'sections' => [
                [
                    'label'   => 'Bad',
                    'content' => [['field' => 'nonexistent_field', 'width' => '1/2']],
                ],
            ],
        ];

        $ctrl = $this->makeController(
            'templates_ctrl',
            ['tb' => self::TB, 'name' => 'should_not_exist'],
            $payload
        );
        $res = $this->callController($ctrl, 'saveTemplate');

        $this->assertSame('error', $res['status']);
        $this->assertSame('template_validation_failed', $res['code']);
        $this->assertArrayHasKey('errors', $res);
    }

    public function testSaveTemplateValidationRejectsInvalidWidth(): void
    {
        $payload = [
            'sections' => [
                [
                    'label'   => 'Bad width',
                    'content' => [['field' => 'name', 'width' => '9/9']],
                ],
            ],
        ];

        $ctrl = $this->makeController(
            'templates_ctrl',
            ['tb' => self::TB, 'name' => 'should_not_exist'],
            $payload
        );
        $res = $this->callController($ctrl, 'saveTemplate');

        $this->assertSame('error', $res['status']);
    }

    public function testSaveTemplateMissingNameReturnsError(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB], ['sections' => []]);
        $res  = $this->callController($ctrl, 'saveTemplate');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testSaveTemplateRequiresSuperAdmin(): void
    {
        $this->setPrivilege(99);
        $ctrl = $this->makeController(
            'templates_ctrl',
            ['tb' => self::TB, 'name' => self::TMP_NAME],
            ['sections' => []]
        );
        $res = $this->callController($ctrl, 'saveTemplate');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
    }

    // ── renameTemplate ───────────────────────────────────────────────────

    public function testRenameTemplateRenamesFile(): void
    {
        $ctrl = $this->makeController('templates_ctrl', [
            'tb'  => self::TB,
            'old' => self::TMP_NAME,
            'new' => self::TMP_NAME2,
        ]);
        $res = $this->callController($ctrl, 'renameTemplate');

        $this->assertSame('success', $res['status']);
        $this->assertSame('template_renamed', $res['code']);

        $oldPath = \Template\Loader::getPath('bdus_test', self::STRIPPED, self::TMP_NAME);
        $newPath = \Template\Loader::getPath('bdus_test', self::STRIPPED, self::TMP_NAME2);
        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileExists($newPath);
    }

    public function testRenameTemplateConflictReturnsError(): void
    {
        // 'default' already exists — renaming tmp_test_renamed → default should fail
        $ctrl = $this->makeController('templates_ctrl', [
            'tb'  => self::TB,
            'old' => self::TMP_NAME2,
            'new' => 'default',
        ]);
        $res = $this->callController($ctrl, 'renameTemplate');

        $this->assertSame('error', $res['status']);
        $this->assertSame('template_name_exists', $res['code']);
    }

    public function testRenameTemplateInvalidNameReturnsError(): void
    {
        $ctrl = $this->makeController('templates_ctrl', [
            'tb'  => self::TB,
            'old' => self::TMP_NAME2,
            'new' => 'invalid name!',
        ]);
        $res = $this->callController($ctrl, 'renameTemplate');

        $this->assertSame('error', $res['status']);
        $this->assertSame('invalid_template_name', $res['code']);
    }

    public function testRenameTemplateNotFoundReturnsError(): void
    {
        $ctrl = $this->makeController('templates_ctrl', [
            'tb'  => self::TB,
            'old' => 'no_such_template',
            'new' => 'anything',
        ]);
        $res = $this->callController($ctrl, 'renameTemplate');

        $this->assertSame('error', $res['status']);
        $this->assertSame('template_not_found', $res['code']);
    }

    // ── deleteTemplate ───────────────────────────────────────────────────

    public function testDeleteTemplateRemovesFile(): void
    {
        $ctrl = $this->makeController('templates_ctrl', [
            'tb'   => self::TB,
            'name' => self::TMP_NAME2,
        ]);
        $res = $this->callController($ctrl, 'deleteTemplate');

        $this->assertSame('success', $res['status']);
        $this->assertSame('template_deleted', $res['code']);

        $path = \Template\Loader::getPath('bdus_test', self::STRIPPED, self::TMP_NAME2);
        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteTemplateNotFoundReturnsError(): void
    {
        $ctrl = $this->makeController('templates_ctrl', [
            'tb'   => self::TB,
            'name' => 'no_such_template',
        ]);
        $res = $this->callController($ctrl, 'deleteTemplate');

        $this->assertSame('error', $res['status']);
        $this->assertSame('template_not_found', $res['code']);
    }

    public function testDeleteTemplateMissingParamsReturnsError(): void
    {
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'deleteTemplate');

        $this->assertSame('error', $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testDeleteTemplateRequiresSuperAdmin(): void
    {
        $this->setPrivilege(99);
        $ctrl = $this->makeController('templates_ctrl', ['tb' => self::TB, 'name' => 'default']);
        $res  = $this->callController($ctrl, 'deleteTemplate');
        $this->setPrivilege(1);

        $this->assertSame('error', $res['status']);
    }
}
