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

    /** Core field names for test__items */
    private array $fieldNames = ['id', 'creator', 'name', 'description', 'status'];

    /** Plugin table IDs for test__items */
    private array $pluginNames = ['test__tags'];

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
                    'plugin'  => 'test__no_such',
                    'content' => [
                        ['field' => 'label', 'width' => '1/1'],
                    ],
                ],
            ],
        ];

        $errors = Loader::validate($tpl, $this->fieldNames, ['test__tags']);
        $this->assertNotEmpty($errors);
        $allErrors = implode(' ', $errors);
        $this->assertStringContainsString('test__no_such', $allErrors);
    }
}
