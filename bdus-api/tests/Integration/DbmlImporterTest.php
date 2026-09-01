<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bdus\DbmlParser;
use Bdus\DbmlImporter;
use Tests\Support\BdusTestCase;

/**
 * Integration tests for DbmlImporter::preview() and ::apply().
 *
 * Uses BdusTestCase infrastructure (in-memory SQLite, system tables).
 * The Config is rebuilt as DB-backed so that apply() writes go to
 * bdus_cfg_tables (in-memory) instead of the shared fixture JSON files,
 * which would corrupt subsequent test runs.
 */
class DbmlImporterTest extends BdusTestCase
{
    private DbmlParser   $parser;
    private DbmlImporter $importer;

    /**
     * Upgrade the shared Config to DB-backed mode by:
     * 1. Seeding bdus_cfg_tables / bdus_cfg_fields from the fixture JSON.
     * 2. Rebuilding static::$cfg with the DB as backend.
     *
     * This ensures apply() writes never touch the fixture files.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Seed DB with fixture tables so preview() can detect existing tables
        $tables = static::$cfg->get('tables') ?? [];
        foreach ($tables as $tbName => $tbData) {
            \Config\ToDB::upsertTable(static::$db, $tbData);
            foreach ($tbData['fields'] ?? [] as $fldData) {
                \Config\ToDB::upsertField(static::$db, $tbName, $fldData);
            }
        }

        // Rebuild cfg backed by the in-memory DB — writes go to bdus_cfg_tables
        static::$cfg = new \Config\Config(
            new \Adbar\Dot(),
            __DIR__ . '/../fixtures/cfg/',
            static::$db
        );
    }

    protected function setUp(): void
    {
        $this->parser   = new DbmlParser();
        $this->importer = new DbmlImporter();
    }

    // ── preview() — validation ────────────────────────────────────────────────

    public function testPreviewNewTableHasNoErrors(): void
    {
        $dbml = <<<'DBML'
Table new_table {
  id integer [pk, increment]
  name varchar
  Note: 'bdus:label=New Table bdus:preview=id,name'
}
DBML;
        $parsed  = $this->parser->parse($dbml);
        $preview = $this->importer->preview($parsed, static::$cfg);

        $this->assertFalse($preview['has_errors']);
        $this->assertCount(1, $preview['tables']);
        $this->assertEmpty($preview['tables'][0]['errors']);
    }

    public function testPreviewDetectsExistingTable(): void
    {
        // 'items' already exists in the fixture cfg
        $dbml = <<<'DBML'
Table items {
  id integer [pk, increment]
}
DBML;
        $parsed  = $this->parser->parse($dbml);
        $preview = $this->importer->preview($parsed, static::$cfg);

        $this->assertTrue($preview['has_errors']);
        $errors = $preview['tables'][0]['errors'];
        $this->assertNotEmpty($errors);
        $this->assertSame('table_already_exists', $errors[0]['code']);
    }

    public function testPreviewRejectsNonIdPrimaryKey(): void
    {
        $dbml = <<<'DBML'
Table bad_table {
  uid integer [pk, increment]
  name varchar
}
DBML;
        $parsed  = $this->parser->parse($dbml);
        $preview = $this->importer->preview($parsed, static::$cfg);

        $this->assertTrue($preview['has_errors']);
        $errors = $preview['tables'][0]['errors'];
        $this->assertSame('pk_must_be_id', $errors[0]['code']);
        $this->assertSame('uid', $errors[0]['field']);
    }

    public function testPreviewWarnsAboutMissingId(): void
    {
        $dbml = <<<'DBML'
Table no_id_table {
  name varchar
}
DBML;
        $parsed  = $this->parser->parse($dbml);
        $preview = $this->importer->preview($parsed, static::$cfg);

        $this->assertFalse($preview['has_errors']);
        $warnCodes = array_column($preview['tables'][0]['warnings'], 'code');
        $this->assertContains('auto_add_id', $warnCodes);
    }

    public function testPreviewWarnsAboutMissingCreator(): void
    {
        $dbml = <<<'DBML'
Table no_creator_table {
  id integer [pk, increment]
  name varchar
}
DBML;
        $parsed  = $this->parser->parse($dbml);
        $preview = $this->importer->preview($parsed, static::$cfg);

        $this->assertFalse($preview['has_errors']);
        $warnCodes = array_column($preview['tables'][0]['warnings'], 'code');
        $this->assertContains('auto_add_creator', $warnCodes);
    }

    public function testPreviewNoCreatorWarningForPlugin(): void
    {
        $dbml = <<<'DBML'
Table my_plugin {
  id integer [pk, increment]
  label varchar
  Note: 'bdus:is_plugin=items'
}
DBML;
        $parsed  = $this->parser->parse($dbml);
        $preview = $this->importer->preview($parsed, static::$cfg);

        $this->assertFalse($preview['has_errors']);
        $warnCodes = array_column($preview['tables'][0]['warnings'], 'code');
        $this->assertNotContains('auto_add_creator', $warnCodes);
        $this->assertTrue($preview['tables'][0]['is_plugin']);
    }

    public function testPreviewMapsTableAnnotations(): void
    {
        $dbml = <<<'DBML'
Table annotated_tb {
  id integer [pk, increment]
  name varchar
  Note: 'bdus:label=My Label bdus:preview=id,name bdus:rs=1 bdus:geodata=1'
}
DBML;
        $parsed  = $this->parser->parse($dbml);
        $preview = $this->importer->preview($parsed, static::$cfg);

        $tb = $preview['tables'][0];
        $this->assertSame('My Label', $tb['label']);
        $this->assertSame(['id', 'name'], $tb['preview']);
        $this->assertTrue($tb['rs']);
        $this->assertTrue($tb['geodata']);
    }

    public function testPreviewMapsVocabularyField(): void
    {
        $dbml = <<<'DBML'
Table with_voc {
  id integer [pk, increment]
  status varchar [note: 'bdus:voc=my_status']
}
DBML;
        $parsed  = $this->parser->parse($dbml);
        $preview = $this->importer->preview($parsed, static::$cfg);

        $fields = array_column($preview['tables'][0]['fields'], null, 'name');
        $this->assertSame('select', $fields['status']['type']);
        $this->assertSame('my_status', $fields['status']['vocabulary_set']);
    }

    public function testPreviewMapsIdFromTbField(): void
    {
        $dbml = <<<'DBML'
Table with_fk {
  id integer [pk, increment]
  cat_ref integer [ref: > categories.id, note: 'bdus:id_from_tb']
}
DBML;
        $parsed  = $this->parser->parse($dbml);
        $preview = $this->importer->preview($parsed, static::$cfg);

        $fields = array_column($preview['tables'][0]['fields'], null, 'name');
        $this->assertSame('select', $fields['cat_ref']['type']);
        $this->assertSame('categories', $fields['cat_ref']['id_from_tb']);
    }

    public function testPreviewIncludesVocabulariesFromMarkedEnums(): void
    {
        $dbml = <<<'DBML'
Enum my_voc {
  alpha
  beta
  // bdus:vocabulary
}

Enum ignored_voc {
  x
  y
}

Table dummy {
  id integer [pk, increment]
}
DBML;
        $parsed  = $this->parser->parse($dbml);
        $preview = $this->importer->preview($parsed, static::$cfg);

        $this->assertCount(1, $preview['vocabularies']);
        $this->assertSame('my_voc', $preview['vocabularies'][0]['name']);
        $this->assertSame(['alpha', 'beta'], $preview['vocabularies'][0]['values']);
    }

    // ── apply() ───────────────────────────────────────────────────────────────

    public function testApplyCreatesTableInDbAndCfg(): void
    {
        $dbml = <<<'DBML'
Table apply_test {
  id integer [pk, increment]
  name varchar
  Note: 'bdus:label=Apply Test bdus:preview=id,name'
}
DBML;
        $parsed = $this->parser->parse($dbml);
        $result = $this->importer->apply($parsed, static::$cfg, static::$db);

        $this->assertContains('apply_test', $result['created']);
        $this->assertEmpty($result['skipped']);

        // Table exists in DB
        $rows = static::$db->query('SELECT * FROM apply_test', [], 'read');
        $this->assertIsArray($rows);

        // Table exists in cfg
        $this->assertNotNull(static::$cfg->get('tables.apply_test'));
        $this->assertSame('Apply Test', static::$cfg->get('tables.apply_test.label'));
    }

    public function testApplySkipsTableWithHardError(): void
    {
        // 'items' already exists — apply must skip it
        $dbml = <<<'DBML'
Table items {
  id integer [pk, increment]
}
DBML;
        $parsed = $this->parser->parse($dbml);
        $result = $this->importer->apply($parsed, static::$cfg, static::$db);

        $this->assertContains('items', $result['skipped']);
        $this->assertEmpty($result['created']);
    }

    public function testApplyInsertsVocabularyRows(): void
    {
        $dbml = <<<'DBML'
Enum apply_voc {
  alpha
  beta
  // bdus:vocabulary
}

Table voc_holder {
  id integer [pk, increment]
}
DBML;
        $parsed = $this->parser->parse($dbml);
        $this->importer->apply($parsed, static::$cfg, static::$db);

        $rows = static::$db->query(
            "SELECT def FROM bdus_vocabularies WHERE voc = 'apply_voc' ORDER BY sort",
            [],
            'read'
        );
        $this->assertCount(2, $rows);
        $this->assertSame('alpha', $rows[0]['def']);
        $this->assertSame('beta',  $rows[1]['def']);
    }

    public function testApplyAddsUserDefinedFieldsToCfg(): void
    {
        $dbml = <<<'DBML'
Table field_test {
  id integer [pk, increment]
  title varchar
  body text
  active boolean
}
DBML;
        $parsed = $this->parser->parse($dbml);
        $this->importer->apply($parsed, static::$cfg, static::$db, 'bdus_test_');

        $this->assertSame('text',      static::$cfg->get('tables.field_test.fields.title.type'));
        $this->assertSame('long_text', static::$cfg->get('tables.field_test.fields.body.type'));
        $this->assertSame('boolean',   static::$cfg->get('tables.field_test.fields.active.type'));
    }
}
