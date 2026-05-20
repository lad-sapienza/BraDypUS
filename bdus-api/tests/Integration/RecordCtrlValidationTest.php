<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * Integration tests for record_ctrl validation (server-side).
 *
 * Covers validatePayload() / validateFieldValue() called inside saveRecord().
 *
 * The items fixture config (tests/fixtures/cfg/items.json) has been
 * enriched with check constraints so all rules can be exercised:
 *   - name        : check="not_empty no_dupl"
 *   - description : max_length=50
 *   - score       : check="int", min="0", max="100"
 *   - email_addr  : check="email"
 *   - geo_data    : check="valid_wkt"
 *   - ref_code    : pattern="^[A-Z]{3}$"
 *   - birth_date  : type="date", min="1900-01-01", max="2100-12-31"
 *
 * Fields that have no matching DB column (score, email_addr, geo_data,
 * ref_code, birth_date) are only used to trigger validation FAILURES — the
 * payload never reaches the DB layer when validation fails.
 */
class RecordCtrlValidationTest extends BdusTestCase
{
    private const TB = 'items';

    // ── helper ────────────────────────────────────────────────────────────────

    /**
     * Assert a saveRecord response is a validation failure and contains at
     * least one error entry for $expectedRule on $expectedField.
     */
    private function assertValidationError(array $res, string $expectedField, string $expectedRule): void
    {
        $this->assertSame('error', $res['status'], 'Expected error status');
        $this->assertSame('validation_failed', $res['code'], 'Expected validation_failed code');
        $this->assertIsArray($res['errors'], 'Expected errors array');
        $this->assertNotEmpty($res['errors'], 'Expected at least one error');

        $matched = false;
        foreach ($res['errors'] as $err) {
            if ($err['field'] === $expectedField && $err['rule'] === $expectedRule) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue(
            $matched,
            "Expected validation error: field={$expectedField}, rule={$expectedRule}. " .
            "Got: " . json_encode($res['errors'])
        );
    }

    // ── required / not_empty ──────────────────────────────────────────────────

    public function testRejectsEmptyRequiredField(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['name' => ''],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'name', 'required');
    }

    public function testRejectsNullRequiredField(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['name' => null],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'name', 'required');
    }

    // ── int ───────────────────────────────────────────────────────────────────

    public function testRejectsNonIntegerForIntField(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['score' => 'abc'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'score', 'int');
    }

    public function testRejectsDecimalForIntField(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['score' => '3.14'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'score', 'int');
    }

    public function testAcceptsValidInteger(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => ['score' => '42'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status'], 'Valid integer should save without error');
    }

    // ── min / max (numeric) ───────────────────────────────────────────────────

    public function testRejectsIntBelowMin(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['score' => '-5'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'score', 'min');
    }

    public function testRejectsIntAboveMax(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['score' => '150'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'score', 'max');
    }

    // ── min / max (date) ──────────────────────────────────────────────────────

    public function testRejectsDateBeforeMin(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['birth_date' => '1850-06-15'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'birth_date', 'min');
    }

    public function testRejectsDateAfterMax(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['birth_date' => '2200-01-01'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'birth_date', 'max');
    }

    public function testAcceptsDateWithinRange(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => ['birth_date' => '1985-03-22'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status'], 'Date within range should save without error');
    }

    // ── email ─────────────────────────────────────────────────────────────────

    public function testRejectsInvalidEmail(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['email_addr' => 'not-an-email'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'email_addr', 'email');
    }

    public function testAcceptsValidEmail(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => ['email_addr' => 'user@example.com'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status'], 'Valid email should save without error');
    }

    // ── valid_wkt ─────────────────────────────────────────────────────────────

    public function testRejectsInvalidWkt(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['geo_data' => 'not a geometry'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'geo_data', 'valid_wkt');
    }

    public function testAcceptsValidWktPoint(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => ['geo_data' => 'POINT(12.5 41.9)'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status'], 'Valid WKT POINT should save without error');
    }

    public function testAcceptsValidWktPolygon(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => ['geo_data' => 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status'], 'Valid WKT POLYGON should save without error');
    }

    // ── pattern / regex ───────────────────────────────────────────────────────

    public function testRejectsPatternMismatch(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['ref_code' => 'abc'],  // lowercase — must be [A-Z]{3}
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'ref_code', 'pattern');
    }

    public function testRejectsPatternWrongLength(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['ref_code' => 'ABCD'],   // 4 chars — pattern expects 3
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'ref_code', 'pattern');
    }

    public function testAcceptsValidPattern(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => ['ref_code' => 'ABC'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status'], 'Value matching pattern should save without error');
    }

    // ── max_length ────────────────────────────────────────────────────────────

    public function testRejectsValueExceedingMaxLength(): void
    {
        $tooLong = str_repeat('x', 51);   // max_length on description is 50
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => ['description' => $tooLong],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'description', 'max_length');
    }

    public function testAcceptsValueAtMaxLength(): void
    {
        $exactly50 = str_repeat('y', 50);
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => ['description' => $exactly50],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        // Should not be a validation failure (DB write succeeds normally)
        $this->assertSame('success', $res['status']);
    }

    // ── no_dupl ───────────────────────────────────────────────────────────────

    public function testRejectsDuplicateValueOnInsert(): void
    {
        // 'Alpha item' is already in the DB (seed id=1)
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            // no id → INSERT path
            'core' => ['name' => 'Alpha item'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'name', 'no_dupl');
    }

    public function testAllowsSameValueOnUpdateSameRecord(): void
    {
        // Updating id=1 with its own name should NOT trigger no_dupl
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => ['name' => 'Alpha item'],   // same record → excluded from count
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status']);
    }

    public function testRejectsDuplicateValueOnUpdateDifferentRecord(): void
    {
        // Updating id=2 with 'Alpha item' (which belongs to id=1) → duplicate
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 2,
            'core' => ['name' => 'Alpha item'],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertValidationError($res, 'name', 'no_dupl');
    }

    // ── multiple errors ───────────────────────────────────────────────────────

    public function testCollectsMultipleErrorsAtOnce(): void
    {
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'core' => [
                'name'       => '',              // required violation
                'score'      => 'not-a-number',  // int violation
                'email_addr' => 'bad-email',     // email violation
            ],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('error',            $res['status']);
        $this->assertSame('validation_failed', $res['code']);
        $this->assertCount(3, $res['errors']);
    }

    // ── buildFieldSchema exposes check[] and normalises not_empty ─────────────

    public function testBuildFieldSchemaExposesCheckArray(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');

        $byName = array_column($res['schema']['fields'], null, 'name');

        // name: check="not_empty no_dupl" → required=true, check=['not_empty','no_dupl']
        $this->assertTrue($byName['name']['required'], '"name" should be required=true');
        $this->assertContains('no_dupl', $byName['name']['check'],
            '"name" check array should contain no_dupl');
    }

    public function testBuildFieldSchemaNormalisesNotEmptyToRequired(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');

        $byName = array_column($res['schema']['fields'], null, 'name');

        // The fixture uses "not_empty" (not "required") — v5 should normalise it
        $this->assertTrue($byName['name']['required'],
            'not_empty in config should produce required=true in schema');
    }

    public function testBuildFieldSchemaExposesMaxLength(): void
    {
        $ctrl = $this->makeController('record_ctrl', ['tb' => self::TB, 'id' => 1]);
        $res  = $this->callController($ctrl, 'getRecord');

        $byName = array_column($res['schema']['fields'], null, 'name');

        $this->assertSame(50, (int)$byName['description']['max_length']);
    }

    // ── valid payload succeeds ────────────────────────────────────────────────

    public function testValidCorePayloadSavesSuccessfully(): void
    {
        // All fields sent are valid → should succeed
        $ctrl = $this->makeController('record_ctrl', [], [
            'tb'   => self::TB,
            'id'   => 1,
            'core' => [
                'name'        => 'Alpha item',        // unique for id=1 (excluded)
                'description' => 'Short description', // well within max_length=50
            ],
        ]);
        $res = $this->callController($ctrl, 'saveRecord');

        $this->assertSame('success', $res['status']);
        $this->assertSame(1, $res['id']);
    }
}
