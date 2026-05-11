<?php
/**
 * @copyright 2007-2024 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;

use DB\DBInterface;
use DB\Export\JSON;
use DB\Export\CSV;
use DB\Export\XLSX;
use DB\Export\SQL;
use DB\Export\HTML;
use DB\Export\XML;
use DB\Export\XLS;

class Export
{
    private array $data;
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

    /**
     * @deprecated Use Export::fromData() instead.
     *             This constructor executes a raw SELECT * without going through
     *             QueryFromRequest, so it bypasses search logic and column selection.
     */
    public function __construct(
        DBInterface $db    = null,
        string      $tb    = null,
        string      $where = null,
        array       $values = null
    ) {
        if ($db === null) {
            // Called via fromData() — fields will be set by the factory.
            return;
        }

        $this->data     = $db->query(
            'SELECT * FROM ' . $tb . ' WHERE ' . ($where ?: '1=1'),
            $values
        );
        $this->metadata = [
            'table'         => $tb,
            'filter'        => $where,
            'filter_values' => $values,
        ];
    }

    // ── v5 API ────────────────────────────────────────────────────────────────

    /**
     * Stream the export directly to the HTTP response and terminate execution.
     *
     * Sets Content-Type, Content-Disposition and outputs the file bytes.
     * Must be called before any other output is sent.
     *
     * @param string $format   'csv' | 'json' | 'xlsx'
     * @param string $filename Base filename without extension (e.g. "paths__manuscripts_1715000000")
     */
    public function streamToResponse(string $format, string $filename): void
    {
        [$mimeType, $ext, $formatter] = $this->resolveFormatter($format);

        $content = $formatter->render($this->data, $this->metadata);

        header('Content-Type: '        . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '.' . $ext . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        // Content-Length omitted intentionally: PHP gzip output compression
        // (zlib.output_compression) would change the actual byte count.

        echo $content;
    }

    // ── v4 legacy API ─────────────────────────────────────────────────────────

    /**
     * @deprecated Use streamToResponse() instead.
     *             Kept for v4 backward compatibility (myExport_ctrl::doExport).
     */
    public function saveToFile(string $format, string $file): bool
    {
        [,, $formatter] = $this->resolveFormatter($format);
        return $formatter->saveToFile($this->data, $this->metadata, $file);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Returns [mimeType, extension, formatterInstance] for a given format key.
     *
     * @throws \Exception on unknown format
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

            // ── Legacy formats (v4 only) ─────────────────────────────────────
            /** @deprecated */
            case 'sql':
                return ['text/plain; charset=utf-8', 'sql', new SQL()];

            /** @deprecated */
            case 'html':
                return ['text/html; charset=utf-8', 'html', new HTML()];

            /** @deprecated */
            case 'xml':
                return ['application/xml; charset=utf-8', 'xml', new XML()];

            /** @deprecated */
            case 'xls':
                return ['application/vnd.ms-excel', 'xls', new XLS()];

            default:
                throw new \Exception("Unknown export format: `$format`");
        }
    }
}
