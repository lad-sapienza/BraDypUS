<?php

namespace Tests\Unit;

use DB\DB;
use DB\Export\CSV;
use DB\Export\Export;
use DB\Export\JSON;
use DB\Export\XLSX;
use PHPUnit\Framework\TestCase;

/**
 * Exports are written row by row from an iterator: same bytes as the old
 * whole-array rendering, flat memory, and errors raised before any output.
 */
class ExportStreamTest extends TestCase
{
    private function rows(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['id' => $i, 'name' => "Città \"{$i}\"", 'note' => $i % 3 ? null : "riga\n{$i}"];
        }
        return $out;
    }

    private function gen(array $rows): \Generator
    {
        yield from $rows;
    }

    private function written(object $fmt, iterable $rows, array $meta = ['table' => 't']): string
    {
        $fh = fopen('php://temp', 'r+');
        $fmt->write($rows, $meta, $fh);
        rewind($fh);
        $s = stream_get_contents($fh);
        fclose($fh);
        return $s;
    }

    public function testJsonIsByteIdenticalToWholeEncoding(): void
    {
        $rows = $this->rows(5);
        $meta = ['table' => 't', 'filter' => 'all', 'query' => null];
        $expected = json_encode(['metadata' => $meta, 'data' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $this->assertSame($expected, $this->written(new JSON(), $this->gen($rows), $meta));
        $this->assertSame($expected, (new JSON())->render($rows, $meta));
    }

    public function testJsonEmptyDataIsValid(): void
    {
        $out = $this->written(new JSON(), $this->gen([]), ['table' => 't']);
        $this->assertSame([], json_decode($out, true)['data']);
        $this->assertSame(
            json_encode(['metadata' => ['table' => 't'], 'data' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            $out
        );
    }

    public function testCsvFromGeneratorMatchesArray(): void
    {
        $rows = $this->rows(7);
        $this->assertSame(
            $this->written(new CSV(), $rows),
            $this->written(new CSV(), $this->gen($rows))
        );
        $parsed = array_map('str_getcsv', explode("\n", trim($this->written(new CSV(), $rows))));
        $this->assertSame(['id', 'name', 'note'], $parsed[0]);
    }

    public function testCsvAndXlsxRejectEmptyDataset(): void
    {
        foreach ([new CSV(), new XLSX()] as $fmt) {
            $this->assertTrue($fmt->requiresRows());
            try {
                $this->written($fmt, $this->gen([]));
                $this->fail(get_class($fmt) . ' accepted an empty dataset');
            } catch (\Exception $e) {
                $this->assertSame('Empty dataset', $e->getMessage());
            }
        }
    }

    public function testXlsxIsAValidZipWithAllRows(): void
    {
        $bytes = $this->written(new XLSX(), $this->gen($this->rows(20)));
        $path  = tempnam(sys_get_temp_dir(), 'xt');
        file_put_contents($path, $bytes);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertNotFalse($sheet);
        $xml = simplexml_load_string($sheet);
        $this->assertNotFalse($xml, 'sheet1.xml must be well-formed XML');
        $this->assertCount(21, $xml->sheetData->row); // header + 20
    }

    public function testXlsxRowLimitIsAnErrorNotATruncatedFile(): void
    {
        $limit = (function () {
            for ($i = 0; $i < XLSX::MAX_ROWS; $i++) {
                yield ['a' => 1];
            }
        })();
        $this->expectExceptionMessage('Too many rows');
        $this->written(new XLSX(), $limit);
    }

    public function testCsvMemoryStaysFlat(): void
    {
        $gen = (function () {
            $text = str_repeat('x', 2000);
            for ($i = 1; $i <= 50000; $i++) {
                yield ['id' => $i, 'text' => $text];
            }
        })();

        $fh = fopen('php://temp/maxmemory:1048576', 'w+');
        gc_collect_cycles();
        $before = memory_get_usage();
        $peak0  = memory_get_peak_usage();
        (new CSV())->write($gen, [], $fh);
        $growth = memory_get_peak_usage() - max($before, $peak0);
        $size = ftell($fh);
        fclose($fh);

        $this->assertGreaterThan(100_000_000, $size); // ~100 MB written
        $this->assertLessThan(10_000_000, max(0, $growth), 'peak memory must not follow the size of the result');
    }

    public function testQueryIterateYieldsSameRowsAsQuery(): void
    {
        $db = new DB('iter_test', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        for ($i = 1; $i <= 2500; $i++) {
            $db->query('INSERT INTO t (v) VALUES (?)', ["v{$i}"], 'boolean');
        }

        $iter = iterator_to_array($db->queryIterate('SELECT * FROM t WHERE id > ? ORDER BY id', [10]), false);
        $this->assertSame($db->query('SELECT * FROM t WHERE id > ? ORDER BY id', [10], 'read'), $iter);

        $this->expectException(\DB\DBException::class);
        iterator_to_array($db->queryIterate('SELECT * FROM missing_table'));
    }

    public function testStreamToResponseRaisesBeforeAnyOutputWhenQueryFails(): void
    {
        $db = new DB('iter_err', ['db_engine' => 'sqlite', 'db_path' => ':memory:']);
        $exp = Export::fromIterable($db->queryIterate('SELECT * FROM nope'), []);

        ob_start();
        try {
            $exp->streamToResponse('csv', 'x');
            $this->fail('expected DBException');
        } catch (\DB\DBException) {
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}
