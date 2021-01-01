<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;


class CSV
{
    public function saveToFile( array $data, array $metadata, string $file ) : bool
    {
        $file .= '.csv';

		$delimiter = ',';
		$enclosure = '"';

		//open file
		$fh = @fopen($file, 'w+');

		if (!$fh) {
            throw new \Exception('Can not open file ' . $file . ' in write mode');
		}

		if (empty($data)) {
            throw new \Exception('Empty dataset');
        }

        if (!fputcsv($fh, array_keys($data[0]), $delimiter, $enclosure)) {
            throw new \Exception('Can not write column names in file ' . $file );
        }

        foreach ( $data as $line ) {
            if (!fputcsv($fh, $line, $delimiter, $enclosure)) {
                throw new \Exception('Can not write data in file ' . $file );
            }
        }
        @fclose($fh);
        
        return true;
    }
}