<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;

class JSON implements ExportInterface
{
    public function render(array $data, array $metadata): string
    {
        return json_encode(
            ['metadata' => $metadata, 'data' => $data],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
    }

}
