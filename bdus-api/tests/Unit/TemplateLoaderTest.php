<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Template\Loader;

/**
 * Unit tests for Template\Loader.
 * No database needed — tests file I/O and validation logic only.
 */
class TemplateLoaderTest extends TestCase
{
    private const PROJECTS_ROOT = __DIR__ . '/../../tests/fixtures/';
    private const APP_NAME      = 'bdus_test';
    private const TB_STRIPPED   = 'items';

    // ── Field / plugin lists matching the fixture config ──────────────────

    /** Core field names for items */
    private array $fieldNames = ['id', 'creator', 'name', 'description', 'status'];

    /** Plugin table IDs for items */
    private array $pluginNames = ['tags'];

    // ── load() ────────────────────────────────────────────────────────────

    public function testLoadReturnsNullForMissingFile(): void
    {
        $result = Loader::load(self::APP_NAME, self::TB_STRIPPED, 'does_not_exist', self::PROJECTS_ROOT);
        $this->assertNull($result);
    }

    public function testLoadReturnsArrayForExistingFile(): void
    {
        $result = Loader::load(self::APP_NAME, self::TB_STRIPPED, 'default', self::PROJECTS_ROOT);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('sections', $result);
    }

    // ── listAvailable() ───────────────────────────────────────────────────

    public function testListAvailableReturnsTemplateNames(): void
    {
        $names = Loader::listAvailable(self::APP_NAME, self::TB_STRIPPED, self::PROJECTS_ROOT);
        $this->assertContains('default', $names);
        $this->assertContains('invalid', $names);
        // Must be sorted
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    // ── validate() ────────────────────────────────────────────────────────

    public function testValidateValidTemplate(): void
    {
        $tpl = Loader::load(self::APP_NAME, self::TB_STRIPPED, 'default', self::PROJECTS_ROOT);
        $this->assertNotNull($tpl);

        $errors = Loader::validate($tpl, $this->fieldNames, $this->pluginNames);
        $this->assertSame([], $errors, 'Expected no validation errors: ' . implode(', ', $errors));
    }

    public function testValidateReturnErrorForMissingSections(): void
    {
        $errors = Loader::validate([], $this->fieldNames, $this->pluginNames);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('sections', $errors[0]);
    }

    public function testValidateReturnErrorForUnknownField(): void
    {
        $tpl = Loader::load(self::APP_NAME, self::TB_STRIPPED, 'invalid', self::PROJECTS_ROOT);
        $this->assertNotNull($tpl);

        $errors = Loader::validate($tpl, $this->fieldNames, $this->pluginNames);
        $this->assertNotEmpty($errors);

        $allErrors = implode(' ', $errors);
        $this->assertStringContainsString('nonexistent_field', $allErrors);
    }

    public function testValidateReturnErrorForInvalidWidth(): void
    {
        $tpl = Loader::load(self::APP_NAME, self::TB_STRIPPED, 'invalid', self::PROJECTS_ROOT);
        $this->assertNotNull($tpl);

        $errors = Loader::validate($tpl, $this->fieldNames, $this->pluginNames);
        $this->assertNotEmpty($errors);

        $allErrors = implode(' ', $errors);
        $this->assertStringContainsString('bad_width', $allErrors);
    }

    public function testValidateReturnErrorForUnknownPlugin(): void
    {
        $tpl = [
            'sections' => [
                [
                    'label'   => 'No Such Plugin',
                    'plugin'  => 'no_such',
                    'content' => [
                        ['field' => 'label', 'width' => '1/1'],
                    ],
                ],
            ],
        ];

        $errors = Loader::validate($tpl, $this->fieldNames, ['tags']);
        $this->assertNotEmpty($errors);
        $allErrors = implode(' ', $errors);
        $this->assertStringContainsString('no_such', $allErrors);
    }

    public function testValidateAcceptsPluginItemsInCoreSection(): void
    {
        $tpl = [
            'sections' => [
                [
                    'label'   => 'Mixed',
                    'content' => [
                        ['field' => $this->fieldNames[0], 'width' => '1/2'],
                        ['plugin' => 'tags', 'field' => '*', 'width' => '1/1'],
                        ['plugin' => 'tags', 'field' => 'any_plugin_field', 'width' => '1/2'],
                    ],
                ],
            ],
        ];

        $this->assertSame([], Loader::validate($tpl, $this->fieldNames, ['tags']));
    }

    public function testValidateRejectsUnknownPluginItemInCoreSection(): void
    {
        $tpl = [
            'sections' => [
                [
                    'label'   => 'Mixed',
                    'content' => [
                        ['plugin' => 'no_such', 'field' => '*', 'width' => '1/1'],
                    ],
                ],
            ],
        ];

        $errors = Loader::validate($tpl, $this->fieldNames, ['tags']);
        $this->assertStringContainsString('unknown_plugin', implode(' ', $errors));
    }

    public function testValidateAcceptsPluginSectionWithEmptyContent(): void
    {
        $tpl = ['sections' => [['label' => 'P', 'plugin' => 'tags', 'content' => []]]];

        $this->assertSame([], Loader::validate($tpl, $this->fieldNames, ['tags']));
    }

    public function testValidateRejectsPluginSectionWithoutContent(): void
    {
        $tpl = ['sections' => [['label' => 'P', 'plugin' => 'tags']]];

        $errors = Loader::validate($tpl, $this->fieldNames, ['tags']);
        $this->assertStringContainsString('content_missing_or_invalid', implode(' ', $errors));
    }
}
