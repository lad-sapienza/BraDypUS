<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;


class JSON
{
    public function saveToFile( array $data, array $metadata, string $file ) : bool
    {
        $file .= '.json';

		$json = json_encode([
            'metadata' => $metadata,
            'data' => $data
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);

        return file_put_contents($file, $json);
    }
}