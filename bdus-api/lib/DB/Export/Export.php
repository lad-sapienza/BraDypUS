<?php
/**
 * @copyright 2007-2024 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;

use DB\Export\JSON;
use DB\Export\CSV;
use DB\Export\XLSX;

class Export
{
    /** Formats resolveFormatter() understands. */
    public const FORMATS = ['csv', 'json', 'xlsx'];

    /** @var iterable<array> */
    private iterable $data;
    private array $metadata;

    // ── Constructors / factories ──────────────────────────────────────────────

    /**
     * Build an Export instance from pre-fetched rows and metadata.
     * This is the v5 path: the caller (e.g. record_ctrl::exportRecords)
     * builds the query via QueryFromRequest and passes the results here.
     *
     * @param array $data     Flat array of associative rows (same as DB::query output).
     * @param array $metadata Arbitrary key/value context (table name, filter, …).
     */
    public static function fromData(array $data, array $metadata): self
    {
        $exp           = new self();
        $exp->data     = $data;
        $exp->metadata = $metadata;
        return $exp;
    }

    // ── v5 API ────────────────────────────────────────────────────────────────

    /**
     * Build an Export instance from rows fetched lazily (a generator, e.g.
     * QueryFromRequest::iterateResults()). Memory use stays flat however many
     * rows the query returns. The generator is consumed once.
     *
     * @param iterable<array> $rows
     */
    public static function fromIterable(iterable $rows, array $metadata): self
    {
        $exp           = new self();
        $exp->data     = $rows;
        $exp->metadata = $metadata;
        return $exp;
    }

    // ── v5 API ────────────────────────────────────────────────────────────────

    /**
     * Stream the export directly to the HTTP response.
     *
     * Everything that can fail before the first byte (the SQL, an empty
     * result, the XLSX row limit) is raised as an exception before any header
     * is sent, so the caller can still answer with a JSON error. CSV and JSON
     * are then written row by row to the output; XLSX is built in a temporary
     * file and copied.
     *
     * @param string $format   'csv' | 'json' | 'xlsx'
     * @param string $filename Base filename without extension (e.g. "manuscripts_1715000000")
     * @throws \Exception before any output is sent
     */
    public function streamToResponse(string $format, string $filename): void
    {
        [$mimeType, $ext, $formatter] = $this->resolveFormatter($format);

        // Run the query now (a generator executes on first advance).
        $rows = $this->data;
        if ($rows instanceof \Generator) {
            $rows->current();
            $empty = !$rows->valid();
        } else {
            $empty = empty($rows);
        }
        if ($empty && $formatter->requiresRows()) {
            throw new \Exception('Empty dataset');
        }

        $sendHeaders = function () use ($mimeType, $filename, $ext): void {
            header('Content-Type: '        . $mimeType);
            header('Content-Disposition: attachment; filename="' . $filename . '.' . $ext . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            // Content-Length omitted intentionally: PHP gzip output compression
            // (zlib.output_compression) would change the actual byte count.
        };

        $out = fopen('php://output', 'wb');

        if ($formatter->streamsDirectly()) {
            $sendHeaders();
            $formatter->write($rows, $this->metadata, $out);
        } else {
            $tmp = fopen('php://temp/maxmemory:1048576', 'w+b');
            try {
                $formatter->write($rows, $this->metadata, $tmp);
                rewind($tmp);
                $sendHeaders();
                stream_copy_to_stream($tmp, $out);
            } finally {
                fclose($tmp);
            }
        }

        fclose($out);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Returns [mimeType, extension, formatterInstance] for a given format key.
     *
     * @throws \InvalidArgumentException on unknown format
     */
    private function resolveFormatter(string $format): array
    {
        switch (strtolower($format)) {
            case 'csv':
                return ['text/csv; charset=utf-8', 'csv', new CSV()];

            case 'json':
                return ['application/json; charset=utf-8', 'json', new JSON()];

            case 'xlsx':
                return [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'xlsx',
                    new XLSX(),
                ];

            default:
                throw new \InvalidArgumentException(
                    "Unknown export format '{$format}' (expected: " . implode(', ', self::FORMATS) . ')'
                );
        }
    }
}
