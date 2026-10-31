<?php
/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;

class CSV implements ExportInterface
{
    use RenderViaWrite;

    private string $delimiter = ',';
    private string $enclosure = '"';

    public function requiresRows(): bool
    {
        return true;
    }

    public function streamsDirectly(): bool
    {
        return true;
    }

    public function write(iterable $rows, array $metadata, $out): void
    {
        $first = true;
        foreach ($rows as $row) {
            if ($first) {
                fputcsv($out, array_keys($row), $this->delimiter, $this->enclosure);
                $first = false;
            }
            fputcsv($out, $row, $this->delimiter, $this->enclosure);
        }

        if ($first) {
            throw new \Exception('Empty dataset');
        }
    }
}
