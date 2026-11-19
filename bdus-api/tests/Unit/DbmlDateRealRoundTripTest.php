<?php

namespace Tests\Unit;

use Bdus\DbmlExporter;
use Bdus\DbmlImporter;
use PHPUnit\Framework\TestCase;

/** #84: DATE and REAL columns map to DBML types and back without turning into TEXT. */
class DbmlDateRealRoundTripTest extends TestCase
{
    public function testImporterMapsDateAndNumbers(): void
    {
        $map = (new \ReflectionClassConstant(DbmlImporter::class, 'TYPE_MAP'))->getValue();
        $this->assertSame('DATE', $map['date']['db_type']);
        foreach (['float', 'double', 'decimal', 'numeric', 'real'] as $t) {
            $this->assertSame('REAL', $map[$t]['db_type'], $t);
        }
        $this->assertSame('TIMESTAMP', $map['datetime']['db_type']);
    }

    public function testExporterMapsDbTypesToDbml(): void
    {
        $map = (new \ReflectionClassConstant(DbmlExporter::class, 'DBTYPE_TO_DBML'))->getValue();
        $this->assertSame('date', $map['DATE']);
        $this->assertSame('float', $map['REAL']);
        // What the exporter writes, the importer reads back to the same db_type.
        $imp = (new \ReflectionClassConstant(DbmlImporter::class, 'TYPE_MAP'))->getValue();
        foreach (['DATE', 'REAL', 'TIMESTAMP', 'INTEGER'] as $db) {
            $this->assertSame($db, $imp[$map[$db]]['db_type'], $db);
        }
    }
}
