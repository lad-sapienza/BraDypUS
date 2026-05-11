<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;

class CSV implements ExportInterface
{
    private string $delimiter = ',';
    private string $enclosure = '"';

    public function render(array $data, array $metadata): string
    {
        if (empty($data)) {
            throw new \Exception('Empty dataset');
        }

        $fh = fopen('php://temp', 'r+');

        fputcsv($fh, array_keys($data[0]), $this->delimiter, $this->enclosure);

        foreach ($data as $row) {
            fputcsv($fh, $row, $this->delimiter, $this->enclosure);
        }

        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);

        return $content;
    }

    /**
     * @deprecated Use Export::streamToResponse() instead.
     */
    public function saveToFile(array $data, array $metadata, string $file): bool
    {
        $file .= '.csv';

        $fh = @fopen($file, 'w+');
        if (!$fh) {
            throw new \Exception('Can not open file ' . $file . ' in write mode');
        }

        if (empty($data)) {
            throw new \Exception('Empty dataset');
        }

        if (!fputcsv($fh, array_keys($data[0]), $this->delimiter, $this->enclosure)) {
            throw new \Exception('Can not write column names in file ' . $file);
        }

        foreach ($data as $row) {
            if (!fputcsv($fh, $row, $this->delimiter, $this->enclosure)) {
                throw new \Exception('Can not write data in file ' . $file);
            }
        }

        @fclose($fh);
        return true;
    }
}
