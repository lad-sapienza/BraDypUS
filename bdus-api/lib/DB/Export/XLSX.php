<?php
/**
 * @copyright 2007-2024 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Minimal XLSX (Office Open XML) writer using only ZipArchive (built-in PHP).
 * Strings are written as inline strings to avoid a shared-string table.
 * No external library required.
 */

namespace DB\Export;

class XLSX implements ExportInterface
{
    // ── Public API ────────────────────────────────────────────────────────────

    public function render(array $data, array $metadata): string
    {
        if (empty($data)) {
            throw new \Exception('Empty dataset');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'bdus_xlsx_');

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
                throw new \Exception('Cannot create XLSX temporary file');
            }

            $zip->addFromString('[Content_Types].xml',       $this->contentTypes());
            $zip->addFromString('_rels/.rels',               $this->rootRels());
            $zip->addFromString('xl/workbook.xml',           $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
            $zip->addFromString('xl/styles.xml',             $this->styles());
            $zip->addFromString('xl/worksheets/sheet1.xml',  $this->sheet($data));
            $zip->close();

            $content = file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }

        return $content;
    }

    /**
     * @deprecated Use Export::streamToResponse() instead.
     */
    public function saveToFile(array $data, array $metadata, string $file): bool
    {
        $file .= '.xlsx';
        return (bool) file_put_contents($file, $this->render($data, $metadata));
    }

    // ── OOXML parts ───────────────────────────────────────────────────────────

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml"  ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml"          ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"' .
            ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"    Target="styles.xml"/>' .
            '</Relationships>';
    }

    private function styles(): string
    {
        // Two cell formats: index 0 = normal, index 1 = bold (for header row)
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="2">' .
            '<font><sz val="11"/><name val="Calibri"/></font>' .
            '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
            '</fonts>' .
            '<fills count="2">' .
            '<fill><patternFill patternType="none"/></fill>' .
            '<fill><patternFill patternType="gray125"/></fill>' .
            '</fills>' .
            '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="2">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .        // style 0: normal
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' . // style 1: bold
            '</cellXfs>' .
            '</styleSheet>';
    }

    private function sheet(array $data): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>';

        // Header row (bold, style index 1)
        $xml .= $this->xmlRow(1, array_keys($data[0]), 1);

        // Data rows (normal, style index 0)
        foreach ($data as $i => $row) {
            $xml .= $this->xmlRow($i + 2, array_values($row), 0);
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Renders a single <row> element with inline-string cells.
     * @param int   $rowNum  1-based row index
     * @param array $values  cell values (scalars)
     * @param int   $style   cellXfs index (0=normal, 1=bold)
     */
    private function xmlRow(int $rowNum, array $values, int $style): string
    {
        $xml = '<row r="' . $rowNum . '">';
        foreach ($values as $colIdx => $value) {
            $ref    = $this->colLetter($colIdx + 1) . $rowNum;
            $escaped = htmlspecialchars((string)($value ?? ''), ENT_XML1, 'UTF-8');
            $xml   .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '">' .
                      '<is><t>' . $escaped . '</t></is></c>';
        }
        $xml .= '</row>';
        return $xml;
    }

    /**
     * Converts a 1-based column index to a spreadsheet letter (1→A, 26→Z, 27→AA …).
     */
    private function colLetter(int $col): string
    {
        $letter = '';
        while ($col > 0) {
            $col--;
            $letter = chr(65 + ($col % 26)) . $letter;
            $col    = intdiv($col, 26);
        }
        return $letter;
    }
}
