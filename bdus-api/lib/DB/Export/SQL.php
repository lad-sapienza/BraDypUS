<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;


class SQL
{
    public function saveToFile( array $data, array $metadata, string $file ) : bool
    {
        $file .= '.sql';

		$sql = [];

		foreach ($data as $row) {
            foreach ($row as $key => &$value) {
                $value = htmlentities(addslashes($value));
            }
            array_push(
                $sql,
                "INSERT INTO {$metadata['table']} (" . implode(', ', array_keys($row)). ") VALUES (\"" . implode('", "', array_values($row)). "\");"
            );
        }
        
		return file_put_contents($file, implode("\n", $sql));
    }
}