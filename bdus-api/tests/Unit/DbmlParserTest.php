<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bdus\DbmlParser;
use PHPUnit\Framework\TestCase;

class DbmlParserTest extends TestCase
{
    private DbmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DbmlParser();
    }

    // ── Basic table parsing ──────────────────────────────────────────────────

    public function testParsesEmptyInput(): void
    {
        $result = $this->parser->parse('');
        $this->assertSame([], $result['tables']);
        $this->assertSame([], $result['enums']);
    }

    public function testParsesMinimalTable(): void
    {
        $dbml = <<<'DBML'
Table items {
  id integer [pk, increment]
  name varchar
}
DBML;
        $result = $this->parser->parse($dbml);

        $this->assertArrayHasKey('items', $result['tables']);
        $tb = $result['tables']['items'];
        $this->assertSame(['id', 'name'], $tb['column_order']);
        $this->assertTrue($tb['columns']['id']['pk']);
        $this->assertTrue($tb['columns']['id']['increment']);
        $this->assertFalse($tb['columns']['name']['pk']);
    }

    public function testParsesTableNote(): void
    {
        $dbml = <<<'DBML'
Table items {
  id integer [pk, increment]
  Note: 'bdus:label=Items bdus:rs=1'
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertSame('bdus:label=Items bdus:rs=1', $result['tables']['items']['note']);
    }

    public function testParsesColumnNote(): void
    {
        $dbml = <<<'DBML'
Table items {
  status varchar [note: 'bdus:voc=item_status']
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertSame('bdus:voc=item_status', $result['tables']['items']['columns']['status']['note']);
    }

    public function testParsesColumnRef(): void
    {
        $dbml = <<<'DBML'
Table items {
  cat_ref integer [ref: > categories.id, note: 'bdus:id_from_tb']
}
DBML;
        $result = $this->parser->parse($dbml);
        $col = $result['tables']['items']['columns']['cat_ref'];
        $this->assertNotNull($col['ref']);
        $this->assertSame('>', $col['ref']['type']);
        $this->assertSame('categories', $col['ref']['table']);
        $this->assertSame('id', $col['ref']['col']);
    }

    public function testParsesNotNull(): void
    {
        $dbml = <<<'DBML'
Table items {
  name varchar [not null]
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertTrue($result['tables']['items']['columns']['name']['not_null']);
    }

    public function testPreservesColumnOrder(): void
    {
        $dbml = <<<'DBML'
Table items {
  id integer [pk]
  name varchar
  status varchar
  description text
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertSame(['id', 'name', 'status', 'description'], $result['tables']['items']['column_order']);
    }

    public function testStripsLineComments(): void
    {
        $dbml = <<<'DBML'
// This is a comment
Table items {
  id integer [pk] // inline comment
  name varchar
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertArrayHasKey('items', $result['tables']);
        $this->assertSame(['id', 'name'], $result['tables']['items']['column_order']);
    }

    public function testDoesNotStripCommentInsideQuote(): void
    {
        $dbml = <<<'DBML'
Table items {
  id integer [pk]
  Note: 'value with // slash'
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertSame('value with // slash', $result['tables']['items']['note']);
    }

    // ── Multiple tables ──────────────────────────────────────────────────────

    public function testParsesMultipleTables(): void
    {
        $dbml = <<<'DBML'
Table items {
  id integer [pk]
}

Table categories {
  id integer [pk]
  name varchar
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertCount(2, $result['tables']);
        $this->assertArrayHasKey('items', $result['tables']);
        $this->assertArrayHasKey('categories', $result['tables']);
    }

    // ── Enum parsing ─────────────────────────────────────────────────────────

    public function testParsesEnum(): void
    {
        $dbml = <<<'DBML'
Enum item_status {
  active
  inactive
  pending
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertArrayHasKey('item_status', $result['enums']);
        $this->assertSame(['active', 'inactive', 'pending'], $result['enums']['item_status']['values']);
    }

    public function testParsesEnumVocabularyComment(): void
    {
        $dbml = <<<'DBML'
Enum item_status {
  active
  // bdus:vocabulary
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertSame('bdus:vocabulary', $result['enums']['item_status']['note']);
        $this->assertSame(['active'], $result['enums']['item_status']['values']);
    }

    // ── Column type normalisation ─────────────────────────────────────────────

    public function testStripsLengthFromType(): void
    {
        $dbml = <<<'DBML'
Table items {
  name varchar(255)
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertSame('varchar', $result['tables']['items']['columns']['name']['type']);
    }

    public function testTypesAreLowercase(): void
    {
        $dbml = <<<'DBML'
Table items {
  count INTEGER
}
DBML;
        $result = $this->parser->parse($dbml);
        $this->assertSame('integer', $result['tables']['items']['columns']['count']['type']);
    }

    // ── Full example (round-trip reference) ──────────────────────────────────

    public function testFullExample(): void
    {
        $dbml = <<<'DBML'
Enum item_status {
  active
  inactive
  // bdus:vocabulary
}

Table items {
  id integer [pk, increment]
  name varchar [not null]
  status varchar [note: 'bdus:voc=item_status']
  cat_ref integer [ref: > categories.id, note: 'bdus:id_from_tb']
  Note: 'bdus:label=Items bdus:rs=1 bdus:preview=id,name,status'
}

Table categories {
  id integer [pk, increment]
  name varchar
  Note: 'bdus:label=Categories'
}
DBML;
        $result = $this->parser->parse($dbml);

        $this->assertCount(2, $result['tables']);
        $this->assertCount(1, $result['enums']);

        $items = $result['tables']['items'];
        $this->assertSame('bdus:label=Items bdus:rs=1 bdus:preview=id,name,status', $items['note']);
        $this->assertSame(['id', 'name', 'status', 'cat_ref'], $items['column_order']);
        $this->assertSame('bdus:voc=item_status', $items['columns']['status']['note']);
        $this->assertSame('categories', $items['columns']['cat_ref']['ref']['table']);

        $enum = $result['enums']['item_status'];
        $this->assertSame(['active', 'inactive'], $enum['values']);
        $this->assertSame('bdus:vocabulary', $enum['note']);
    }
}
