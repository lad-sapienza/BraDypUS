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

    // ── refs → relations ──────────────────────────────────────────────────────

    private function previewEntry(array $preview, string $name): array
    {
        foreach ($preview['tables'] as $t) {
            if ($t['name'] === $name) {
                return $t;
            }
        }
        $this->fail("table $name not in preview");
    }

    private function warningCodes(array $entry): array
    {
        return array_values(array_filter(
            array_column($entry['warnings'], 'code'),
            fn($c) => str_starts_with($c, 'ref_') || str_starts_with($c, 'invalid_')
        ));
    }

    private function relationRow(string $fromTb, string $fromCol): ?array
    {
        $rows = static::$db->query(
            'SELECT * FROM bdus_cfg_relations WHERE from_tb = ? AND from_col = ?',
            [$fromTb, $fromCol],
            'read'
        );
        return $rows[0] ?? null;
    }

    public function testPreviewListsRelationForRefWithoutIdFromTb(): void
    {
        $parsed = $this->parser->parse(<<<'DBML'
Table rel_site {
  id integer [pk, increment]
  name varchar
}
Table rel_find {
  id integer [pk, increment]
  site_id integer [ref: > rel_site.id]
}
DBML);
        $entry = $this->previewEntry($this->importer->preview($parsed, static::$cfg), 'rel_find');

        $this->assertSame([[
            'from_col' => 'site_id', 'to_tb' => 'rel_site', 'to_col' => 'id',
            'on_delete' => 'RESTRICT', 'on_update' => 'CASCADE',
        ]], $entry['relations']);
        // A plain ref does not turn the column into a select
        $this->assertSame('text', $entry['fields'][1]['type']);
    }

    public function testPreviewReadsPolicyAnnotationsAndRejectsBadOnes(): void
    {
        $parsed = $this->parser->parse(<<<'DBML'
Table pol_a {
  id integer [pk, increment]
}
Table pol_b {
  id integer [pk, increment]
  a_id integer [ref: > pol_a.id, note: 'bdus:on_delete=SET NULL bdus:on_update=no action']
  a2_id integer [ref: > pol_a.id, note: 'bdus:on_delete=EXPLODE']
}
DBML);
        $entry = $this->previewEntry($this->importer->preview($parsed, static::$cfg), 'pol_b');

        $this->assertSame('SET NULL',  $entry['relations'][0]['on_delete']);
        $this->assertSame('NO ACTION', $entry['relations'][0]['on_update']);
        $this->assertSame('RESTRICT',  $entry['relations'][1]['on_delete']);
        $this->assertContains('invalid_on_delete', $this->warningCodes($entry));
    }

    public function testPreviewSkipsRefsItCannotApply(): void
    {
        $parsed = $this->parser->parse(<<<'DBML'
Table skip_main {
  id integer [pk, increment]
  ghost_id integer [ref: > nowhere.id]
  bad_col integer [ref: > skip_main.nope]
  reverse_id integer [ref: < skip_other.id]
  into_plugin integer [ref: > skip_plug.id]
}
Table skip_other {
  id integer [pk, increment]
}
Table skip_plug {
  id integer [pk, increment]
  id_link integer
  Note: 'bdus:is_plugin=skip_main'
}
DBML);
        $preview = $this->importer->preview($parsed, static::$cfg);
        $main    = $this->previewEntry($preview, 'skip_main');

        $this->assertSame([], $main['relations']);
        $this->assertSame(
            ['ref_target_missing', 'ref_target_missing', 'ref_direction_unsupported', 'ref_plugin_not_allowed'],
            $this->warningCodes($main)
        );
    }

    public function testApplyCreatesRelationAndForeignKey(): void
    {
        // The target is defined AFTER the table that refers to it
        $parsed = $this->parser->parse(<<<'DBML'
Table ap_find {
  id integer [pk, increment]
  site_id integer [ref: > ap_site.id, note: 'bdus:on_delete=CASCADE']
}
Table ap_site {
  id integer [pk, increment]
  name varchar
}
DBML);
        $result = $this->importer->apply($parsed, static::$cfg, static::$db);

        $this->assertEqualsCanonicalizing(['ap_find', 'ap_site'], $result['created']);
        $this->assertSame([], array_filter($result['warnings'], fn($w) => str_starts_with($w, 'relation_skipped')));

        $row = $this->relationRow('ap_find', 'site_id');
        $this->assertNotNull($row);
        $this->assertSame('ap_site', $row['to_tb']);
        $this->assertSame('id', $row['to_col']);
        $this->assertSame('CASCADE', $row['on_delete']);
        $this->assertSame('CASCADE', $row['on_update']);

        $alter = new \DB\Alter(static::$db);
        $this->assertTrue($alter->hasForeignKey('ap_find', 'site_id'));
    }

    public function testApplyDoesNotTouchExistingTables(): void
    {
        $before = static::$db->query('SELECT COUNT(*) AS n FROM bdus_cfg_relations WHERE from_tb = ?', ['items'], 'read')[0]['n'];

        // A new table may refer to an existing one; nothing is written on the old one
        $parsed = $this->parser->parse(<<<'DBML'
Table ex_new {
  id integer [pk, increment]
  item_id integer [ref: > items.id]
}
DBML);
        $result = $this->importer->apply($parsed, static::$cfg, static::$db);

        $this->assertSame(['ex_new'], $result['created']);
        $this->assertNotNull($this->relationRow('ex_new', 'item_id'));
        $after = static::$db->query('SELECT COUNT(*) AS n FROM bdus_cfg_relations WHERE from_tb = ?', ['items'], 'read')[0]['n'];
        $this->assertSame($before, $after);
    }

    public function testApplyReportsSkippedRelationWithoutLeavingARow(): void
    {
        $parsed = $this->parser->parse(<<<'DBML'
Table sk_find {
  id integer [pk, increment]
  site_id integer [ref: > sk_site.id]
}
Table sk_site {
  pk_wrong integer [pk, increment]
}
DBML);
        $result = $this->importer->apply($parsed, static::$cfg, static::$db);

        $this->assertSame(['sk_find'], $result['created']);
        $this->assertContains('sk_site', $result['skipped']);
        $this->assertNull($this->relationRow('sk_find', 'site_id'));
    }

    public function testRelationRoundTripThroughExportAndImport(): void
    {
        $parsed = $this->parser->parse(<<<'DBML'
Table rt_a {
  id integer [pk, increment]
}
Table rt_b {
  id integer [pk, increment]
  a_id integer [ref: > rt_a.id, note: 'bdus:on_delete=SET NULL']
}
DBML);
        $this->importer->apply($parsed, static::$cfg, static::$db);

        $tables = array_filter(
            static::$cfg->get('tables'),
            fn($name) => in_array($name, ['rt_a', 'rt_b'], true),
            ARRAY_FILTER_USE_KEY
        );
        $rels = static::$db->query(
            "SELECT from_tb, from_col, to_tb, to_col, on_delete, on_update FROM bdus_cfg_relations WHERE from_tb = 'rt_b'",
            [], 'read'
        );
        $dbml = (new \Bdus\DbmlExporter())->export($tables, [], '', $rels);

        $this->assertStringContainsString('ref: > rt_a.id', $dbml);
        $this->assertStringContainsString('bdus:on_delete=SET NULL', $dbml);

        // The exported text, read back against an app that has neither table, yields the same relation
        $again = $this->parser->parse(str_replace(['rt_a', 'rt_b'], ['rt2_a', 'rt2_b'], $dbml));
        $entry = $this->previewEntry($this->importer->preview($again, static::$cfg), 'rt2_b');
        $this->assertSame('SET NULL', $entry['relations'][0]['on_delete']);
        $this->assertSame('rt2_a',    $entry['relations'][0]['to_tb']);
    }
}
