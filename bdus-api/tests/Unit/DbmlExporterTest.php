<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bdus\DbmlExporter;
use PHPUnit\Framework\TestCase;

class DbmlExporterTest extends TestCase
{
    private DbmlExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new DbmlExporter();
    }

    // ── Header ────────────────────────────────────────────────────────────────

    public function testOutputContainsHeader(): void
    {
        $out = $this->exporter->export([], []);
        $this->assertStringContainsString('// BraDypUS DBML export', $out);
    }

    public function testOutputContainsAppName(): void
    {
        $out = $this->exporter->export([], [], 'my_app');
        $this->assertStringContainsString('my_app', $out);
    }

    // ── Table rendering ───────────────────────────────────────────────────────

    public function testRendersBasicTable(): void
    {
        $tables = [
            'items' => [
                'label'    => 'Items',
                'preview'  => ['id', 'name'],
                'order'    => 'id',
                'id_field' => 'id',
                'fields'   => [
                    'id'   => ['name' => 'id',   'label' => 'Id',   'type' => 'text', 'db_type' => 'INTEGER'],
                    'name' => ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'db_type' => 'TEXT'],
                ],
            ],
        ];

        $out = $this->exporter->export($tables, []);
        $this->assertStringContainsString('Table items {', $out);
        $this->assertStringContainsString('id integer [pk, increment]', $out);
        $this->assertStringContainsString('name varchar', $out);
    }

    public function testRendersTableNote(): void
    {
        $tables = [
            'items' => [
                'label'    => 'Items',
                'preview'  => ['id', 'name'],
                'order'    => 'id',
                'id_field' => 'id',
                'rs'       => true,
                'fields'   => [],
            ],
        ];

        $out = $this->exporter->export($tables, []);
        $this->assertStringContainsString("bdus:label=Items", $out);
        $this->assertStringContainsString("bdus:rs=1", $out);
        $this->assertStringContainsString("bdus:preview=id,name", $out);
    }

    public function testRendersVocabularyField(): void
    {
        $tables = [
            'items' => [
                'label'  => 'Items',
                'fields' => [
                    'status' => [
                        'name'           => 'status',
                        'label'          => 'Status',
                        'type'           => 'select',
                        'db_type'        => 'TEXT',
                        'vocabulary_set' => 'item_status',
                    ],
                ],
            ],
        ];

        $out = $this->exporter->export($tables, []);
        $this->assertStringContainsString("bdus:voc=item_status", $out);
    }

    public function testRendersIdFromTbField(): void
    {
        $tables = [
            'items' => [
                'label'  => 'Items',
                'fields' => [
                    'cat_ref' => [
                        'name'       => 'cat_ref',
                        'label'      => 'Category',
                        'type'       => 'select',
                        'db_type'    => 'INTEGER',
                        'id_from_tb' => 'categories',
                    ],
                ],
            ],
        ];

        $out = $this->exporter->export($tables, []);
        $this->assertStringContainsString('ref: > categories.id', $out);
        $this->assertStringContainsString('bdus:id_from_tb', $out);
    }

    public function testRendersBooleanTypeAnnotation(): void
    {
        $tables = [
            'items' => [
                'label'  => 'Items',
                'fields' => [
                    'active' => [
                        'name'    => 'active',
                        'label'   => 'Active',
                        'type'    => 'boolean',
                        'db_type' => 'INTEGER',
                    ],
                ],
            ],
        ];

        $out = $this->exporter->export($tables, []);
        $this->assertStringContainsString('bdus:type=boolean', $out);
    }

    public function testRendersLongTextTypeAnnotation(): void
    {
        $tables = [
            'items' => [
                'label'  => 'Items',
                'fields' => [
                    'notes' => [
                        'name'    => 'notes',
                        'label'   => 'Notes',
                        'type'    => 'long_text',
                        'db_type' => 'TEXT',
                    ],
                ],
            ],
        ];

        $out = $this->exporter->export($tables, []);
        $this->assertStringContainsString('bdus:type=long_text', $out);
    }

    // ── Enum / vocabulary rendering ───────────────────────────────────────────

    public function testRendersVocabularyEnum(): void
    {
        $vocItems = [
            ['voc' => 'item_status', 'def' => 'active',   'sort' => 1],
            ['voc' => 'item_status', 'def' => 'inactive',  'sort' => 2],
            ['voc' => 'item_status', 'def' => 'pending',  'sort' => 3],
        ];

        $out = $this->exporter->export([], $vocItems);
        $this->assertStringContainsString('Enum item_status {', $out);
        $this->assertStringContainsString('  "active"', $out);
        $this->assertStringContainsString('  "inactive"', $out);
        $this->assertStringContainsString("  // bdus:vocabulary", $out);
    }

    public function testRendersMultipleVocabularies(): void
    {
        $vocItems = [
            ['voc' => 'status', 'def' => 'active', 'sort' => 1],
            ['voc' => 'type',   'def' => 'a',       'sort' => 1],
            ['voc' => 'type',   'def' => 'b',       'sort' => 2],
        ];

        $out = $this->exporter->export([], $vocItems);
        $this->assertStringContainsString('Enum status {', $out);
        $this->assertStringContainsString('Enum type {', $out);
    }

    // ── Plugin table ──────────────────────────────────────────────────────────

    public function testRendersPluginAnnotation(): void
    {
        $tables = [
            'tags' => [
                'label'     => 'Tags',
                'is_plugin' => 1,
                'plugin_of' => 'items',
                'fields'    => [],
            ],
        ];

        $out = $this->exporter->export($tables, []);
        $this->assertStringContainsString('bdus:is_plugin=items', $out);
    }

    // ── Geodata / fuzzy_date ──────────────────────────────────────────────────

    public function testRendersGeodataAnnotation(): void
    {
        $tables = [
            'items' => [
                'label'    => 'Items',
                'geodata'  => true,
                'fields'   => [],
            ],
        ];

        $out = $this->exporter->export($tables, []);
        $this->assertStringContainsString('bdus:geodata=1', $out);
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    private function relTables(): array
    {
        $id = ['name' => 'id', 'label' => 'Id', 'type' => 'text', 'db_type' => 'INTEGER'];
        return [
            'sites' => ['label' => 'Sites', 'fields' => ['id' => $id]],
            'finds' => ['label' => 'Finds', 'fields' => [
                'id'      => $id,
                'site_id' => ['name' => 'site_id', 'label' => 'Site', 'type' => 'text', 'db_type' => 'INTEGER'],
                'lookup'  => ['name' => 'lookup', 'label' => 'Lookup', 'type' => 'select', 'db_type' => 'INTEGER', 'id_from_tb' => 'sites'],
            ]],
        ];
    }

    public function testRelationBecomesRefWithoutIdFromTb(): void
    {
        $rels = [['from_tb' => 'finds', 'from_col' => 'site_id', 'to_tb' => 'sites', 'to_col' => 'id',
                  'on_delete' => 'RESTRICT', 'on_update' => 'CASCADE']];
        $out = $this->exporter->export($this->relTables(), [], '', $rels);

        $this->assertStringContainsString("site_id integer [ref: > sites.id]", $out);
        $this->assertStringNotContainsString('bdus:on_delete', $out);
    }

    public function testRelationPoliciesAreExportedOnlyWhenNotDefault(): void
    {
        $rels = [['from_tb' => 'finds', 'from_col' => 'site_id', 'to_tb' => 'sites', 'to_col' => 'id',
                  'on_delete' => 'SET NULL', 'on_update' => 'RESTRICT']];
        $out = $this->exporter->export($this->relTables(), [], '', $rels);

        $this->assertStringContainsString("note: 'bdus:on_delete=SET NULL bdus:on_update=RESTRICT'", $out);
    }

    public function testRelationAndIdFromTbEmitOneRef(): void
    {
        $rels = [['from_tb' => 'finds', 'from_col' => 'lookup', 'to_tb' => 'sites', 'to_col' => 'id',
                  'on_delete' => 'RESTRICT', 'on_update' => 'CASCADE']];
        $out = $this->exporter->export($this->relTables(), [], '', $rels);

        $this->assertSame(1, substr_count($out, 'ref: > sites.id'));
        $this->assertStringContainsString('bdus:id_from_tb', $out);
    }

    public function testPluginAttachmentIsNotExportedAsRef(): void
    {
        $tables = $this->relTables();
        unset($tables['finds']['fields']['lookup']);
        $tables['plug'] = ['label' => 'Plug', 'is_plugin' => true, 'plugin_of' => 'sites', 'fields' => [
            'id'      => ['name' => 'id', 'label' => 'Id', 'type' => 'text', 'db_type' => 'INTEGER'],
            'id_link' => ['name' => 'id_link', 'label' => 'Link', 'type' => 'text', 'db_type' => 'INTEGER'],
        ]];
        $rels = [['from_tb' => 'plug', 'from_col' => 'id_link', 'to_tb' => 'sites', 'to_col' => 'id',
                  'on_delete' => 'RESTRICT', 'on_update' => 'CASCADE']];
        $out = $this->exporter->export($tables, [], '', $rels);

        $this->assertStringNotContainsString('ref:', $out);
        $this->assertStringContainsString('bdus:is_plugin=sites', $out);
    }
}
