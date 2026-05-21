<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Regression tests for record_ctrl::getFieldOptions().
 *
 * Bugs these tests guard against:
 *
 *  1. Response format regression: returnJson() received a plain numeric array
 *     (the result of resolveFieldOptions) merged via PHP's + operator, producing
 *     {"status":"success","0":{...},"1":{...}} instead of the intended
 *     {"status":"success","options":[...]}.  The fix wraps the array in a named
 *     key: returnJson(['options' => $this->resolveFieldOptions(...)]).
 *     → testGetFieldOptionsResponseShapeHasOptionsKey (and related assertions)
 *
 *  2. Vocabulary table name regression: the query used
 *     "{$this->prefix}vocabularies" which resolved to the bare table name
 *     "vocabularies" (no such table in BraDypUS v5, which always uses
 *     "bdus_vocabularies").  Any test exercising a vocabulary_set field would
 *     have thrown a DBException.
 *     → testGetFieldOptionsVocabularyReturnsCorrectValues
 *
 *  3. Config field-lookup regression: resolveFieldOptions used
 *     $cfg->get("tables.tb.fields.fieldname") which ignores the field-name
 *     segment and returns all fields, not just the requested one. This caused
 *     every field to behave as if it had no options_source, returning [].
 *     → testGetFieldOptionsStaticDicReturnsCorrectValues
 *       testGetFieldOptionsStaticDicReturnsOnlyThatFieldsOptions
 */
class RecordCtrlFieldOptionsTest extends BdusTestCase
{
    private const TB = 'items';

    // ── parameter validation ──────────────────────────────────────────────

    public function testGetFieldOptionsMissingTbReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['fld' => 'lang_code']);
        $res  = $this->callController($ctrl, 'getFieldOptions');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    public function testGetFieldOptionsMissingFldReturnsError(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB]);
        $res  = $this->callController($ctrl, 'getFieldOptions');

        $this->assertSame('error',             $res['status']);
        $this->assertSame('parameter_missing', $res['code']);
    }

    // ── response shape (regression #1) ───────────────────────────────────

    /**
     * The response must be {"status":"success","options":[...]}, not
     * {"status":"success","0":{...},"1":{...},...}.
     */
    public function testGetFieldOptionsResponseShapeHasOptionsKey(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'  => self::TB,
            'fld' => 'lang_code',   // dic field: ['en','fr','de']
        ]);
        $res = $this->callController($ctrl, 'getFieldOptions');

        $this->assertSame('success', $res['status']);
        $this->assertArrayHasKey('options', $res,
            'Response must contain an "options" key, not bare numeric keys.'
        );
        $this->assertIsArray($res['options'],
            '"options" value must be a JSON array.'
        );
        // Guard that the old broken format is NOT present
        $this->assertArrayNotHasKey(0, $res,
            'Numeric key "0" must not appear at the top level of the response.'
        );
    }

    // ── static dic options (regressions #1 and #3) ───────────────────────

    /**
     * A field with dic:["en","fr","de"] must return exactly those three options.
     * Before the fix, resolveFieldOptions returned [] because the config path
     * "tables.items.fields.lang_code" was resolved to all fields instead of
     * just lang_code, so $cfg['dic'] was never found.
     */
    public function testGetFieldOptionsStaticDicReturnsCorrectValues(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'  => self::TB,
            'fld' => 'lang_code',
        ]);
        $res = $this->callController($ctrl, 'getFieldOptions');

        $this->assertSame('success', $res['status']);
        $this->assertCount(3, $res['options']);

        $values = array_column($res['options'], 'value');
        $this->assertContains('en', $values);
        $this->assertContains('fr', $values);
        $this->assertContains('de', $values);
    }

    /**
     * Options for lang_code must contain only its own three entries, not entries
     * from other fields that happen to have a dic (e.g. if all fields were merged).
     */
    public function testGetFieldOptionsStaticDicReturnsOnlyThatFieldsOptions(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'  => self::TB,
            'fld' => 'lang_code',
        ]);
        $res = $this->callController($ctrl, 'getFieldOptions');

        // Exactly 3 entries from dic:["en","fr","de"] — not a superset of all fields
        $this->assertCount(3, $res['options']);
    }

    public function testGetFieldOptionsEachOptionHasValueAndLabel(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'  => self::TB,
            'fld' => 'lang_code',
        ]);
        $res = $this->callController($ctrl, 'getFieldOptions');

        foreach ($res['options'] as $opt) {
            $this->assertArrayHasKey('value', $opt);
            $this->assertArrayHasKey('label', $opt);
        }
    }

    // ── vocabulary_set options (regression #2) ────────────────────────────

    /**
     * A field with vocabulary_set:"test_cat" must return the entries from the
     * bdus_vocabularies table (seeded with Cat-A / Cat-B / Cat-C).
     * Before the fix this threw: "no such table: vocabularies".
     */
    public function testGetFieldOptionsVocabularyReturnsCorrectValues(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'  => self::TB,
            'fld' => 'category',   // vocabulary_set: "test_cat"
        ]);
        $res = $this->callController($ctrl, 'getFieldOptions');

        $this->assertSame('success', $res['status'],
            'A vocabulary_set field must not error (check bdus_vocabularies table name).'
        );
        $this->assertCount(3, $res['options'],
            'Expected exactly 3 entries seeded for voc="test_cat".'
        );

        $values = array_column($res['options'], 'value');
        $this->assertContains('Cat-A', $values);
        $this->assertContains('Cat-B', $values);
        $this->assertContains('Cat-C', $values);
    }

    /**
     * Vocabulary lookup must be scoped to its own set; entries from other
     * vocabulary sets must not bleed in.
     */
    public function testGetFieldOptionsVocabularyDoesNotReturnOtherSets(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'  => self::TB,
            'fld' => 'category',
        ]);
        $res = $this->callController($ctrl, 'getFieldOptions');

        $values = array_column($res['options'], 'value');
        $this->assertNotContains('Other-X', $values,
            'Entries from a different voc set must not appear.'
        );
    }

    // ── unknown table ─────────────────────────────────────────────────────

    public function testGetFieldOptionsUnknownTableReturnsEmptyOptions(): void
    {
        $ctrl = $this->makeController('record_ctrl', [
            'tb'  => 'nonexistent_table',
            'fld' => 'lang_code',
        ]);
        $res = $this->callController($ctrl, 'getFieldOptions');

        // Not an error — just empty (table not in config → resolveFieldOptions returns [])
        $this->assertSame('success', $res['status']);
        $this->assertSame([], $res['options']);
    }
}
