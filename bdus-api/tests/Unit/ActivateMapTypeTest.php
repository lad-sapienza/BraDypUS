<?php

namespace Tests\Unit;

use Config\Activate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SQL column type → BraDypUS field type / db_type, as used when activating an existing table or view. */
class ActivateMapTypeTest extends TestCase
{
    public static function types(): array
    {
        return [
            // plain text is one line: SQLite/PostgreSQL use it for every string
            ['TEXT',              'text',      'TEXT'],
            ['text',              'text',      'TEXT'],
            ['VARCHAR(255)',      'text',      'TEXT'],
            ['character varying', 'text',      'TEXT'],
            ['',                  'text',      'TEXT'],     // SQLite expression column
            ['MEDIUMTEXT',        'long_text', 'TEXT'],
            ['LONGTEXT',          'long_text', 'TEXT'],
            ['CLOB',              'long_text', 'TEXT'],
            ['INTEGER',           'text',      'INTEGER'],
            ['bigint',            'text',      'INTEGER'],
            ['smallint(6)',       'text',      'INTEGER'],
            ['serial',            'text',      'INTEGER'],
            ['int4',              'text',      'INTEGER'],
            ['DOUBLE PRECISION',  'text',      'TEXT'],
            ['decimal(10,2)',     'text',      'TEXT'],
            ['DATE',              'date',      'TEXT'],
            ['datetime',          'date',      'TIMESTAMP'],
            ['timestamp without time zone', 'date', 'TIMESTAMP'],
            ['BOOLEAN',           'boolean',   'INTEGER'],
            ['bool',              'boolean',   'INTEGER'],
        ];
    }

    #[DataProvider('types')]
    public function testMapType(string $sql, string $type, string $dbType): void
    {
        $this->assertSame(['type' => $type, 'db_type' => $dbType], Activate::mapType($sql), "type '$sql'");
    }

    public function testReasonRules(): void
    {
        $base = ['name' => 'ok_name', 'kind' => 'table', 'has_id' => true, 'has_creator' => false, 'view_writable' => null];
        $this->assertNull(Activate::reason($base, []));
        $this->assertSame('already_configured', Activate::reason($base, ['ok_name']));
        $this->assertSame('invalid_name', Activate::reason(['name' => '1bad'] + $base, []));
        $this->assertSame('invalid_name', Activate::reason(['name' => 'has space'] + $base, []));
        $this->assertSame('missing_id_column', Activate::reason(['has_id' => false] + $base, []));

        $view = ['kind' => 'view', 'view_writable' => 'none'] + $base;
        $this->assertNull(Activate::reason($view, []), 'a read-only view needs no creator');
        $this->assertSame('missing_creator_column', Activate::reason(['view_writable' => 'probe'] + $view, []));
        $this->assertSame('missing_creator_column', Activate::reason(['view_writable' => 'trigger'] + $view, []));
        $this->assertNull(Activate::reason(['view_writable' => 'probe', 'has_creator' => true] + $view, []));
        $this->assertNull(Activate::reason(['has_creator' => false] + $base, []), 'a table without creator is allowed');
    }
}
