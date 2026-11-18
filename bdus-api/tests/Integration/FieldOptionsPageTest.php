<?php

namespace Tests\Integration;

use Tests\Support\BdusTestCase;

/**
 * The select options that grow with the data (the distinct values of a column,
 * the records of a lookup table) come in pages: at most `limit`, a `truncated`
 * flag, a server-side `q` search, and the already selected records kept in.
 *
 * Fixture: items.status takes its values from itself (active ×3, inactive,
 * pending); items.cat_ref points at categories (Ceramics, Metal, Charcoal).
 */
class FieldOptionsPageTest extends BdusTestCase
{
    private function options(string $fld, array $get = []): array
    {
        return $this->callController(
            $this->makeController('Bdus\\Controllers\\Record', ['tb' => 'items', 'fld' => $fld] + $get),
            'getFieldOptions'
        );
    }

    private static function labels(array $res): array
    {
        return array_column($res['options'], 'label');
    }

    public function testACompleteListIsUnchangedAndNotTruncated(): void
    {
        $res = $this->options('status');

        $this->assertSame('success', $res['status']);
        $this->assertFalse($res['truncated']);
        $this->assertSame(['active', 'inactive', 'pending'], self::labels($res), 'alphabetical, as before');
    }

    public function testACutValueListKeepsTheMostUsedAndSaysSo(): void
    {
        $res = $this->options('status', ['limit' => 2]);

        $this->assertTrue($res['truncated']);
        $this->assertCount(2, $res['options']);
        $this->assertSame('active', $res['options'][0]['label'], 'the most used value comes first');
    }

    public function testALimitEqualToTheSizeIsNotTruncated(): void
    {
        $res = $this->options('status', ['limit' => 3]);

        $this->assertFalse($res['truncated']);
        $this->assertCount(3, $res['options']);
    }

    public function testSearchReachesValuesOutsideThePage(): void
    {
        $res = $this->options('status', ['limit' => 1, 'q' => 'pend']);

        $this->assertSame(['pending'], self::labels($res));
        $this->assertFalse($res['truncated']);
    }

    public function testSearchTreatsWildcardsAsText(): void
    {
        $this->assertSame([], $this->options('status', ['q' => '%'])['options']);
        $this->assertSame([], $this->options('status', ['q' => '_ctive'])['options']);
    }

    public function testSearchRanksPrefixMatchesFirst(): void
    {
        // "ctive" is contained by active and inactive, the start of neither
        $this->assertEqualsCanonicalizing(['active', 'inactive'], self::labels($this->options('status', ['q' => 'ctive'])));
        // "in" starts inactive and is only contained by pending
        $this->assertSame(['inactive', 'pending'], self::labels($this->options('status', ['q' => 'in'])));
    }

    public function testALookupListIsCutByLabelAndFlagged(): void
    {
        $res = $this->options('cat_ref', ['limit' => 2]);

        $this->assertTrue($res['truncated']);
        $this->assertSame(['Ceramics', 'Charcoal'], self::labels($res));
    }

    public function testALookupSearchRunsOnTheServer(): void
    {
        $res = $this->options('cat_ref', ['limit' => 1, 'q' => 'met']);

        $this->assertSame(['Metal'], self::labels($res));
        $this->assertFalse($res['truncated']);
    }

    public function testSelectedLookupRecordsStayInEvenOutsideThePage(): void
    {
        // Metal (id 2) is not in the first page of one; asking for it keeps it in, with its label.
        $res = $this->options('cat_ref', ['limit' => 1, 'include' => '2']);

        $this->assertTrue($res['truncated']);
        $this->assertSame(['Ceramics', 'Metal'], self::labels($res));
        $this->assertCount(1, array_filter($res['options'], fn($o) => (string) $o['value'] === '2'));
    }

    public function testIncludeIsNotDuplicatedAndIgnoresJunk(): void
    {
        $res = $this->options('cat_ref', ['include' => '1;abc;1; ;2']);

        $this->assertSame(['Ceramics', 'Charcoal', 'Metal'], self::labels($res));
    }

    public function testVocabulariesAndStaticListsAreNeverCut(): void
    {
        $res = $this->options('lang_code', ['limit' => 1]);

        $this->assertFalse($res['truncated']);
        $this->assertGreaterThan(1, count($res['options']));
    }

    public function testLimitIsClamped(): void
    {
        $this->assertFalse($this->options('status', ['limit' => 'many'])['truncated'], 'junk falls back to the default');
        $this->assertTrue($this->options('status', ['limit' => 0])['truncated'], '0 is raised to 1');
        $this->assertCount(1, $this->options('status', ['limit' => 0])['options']);
    }
}
