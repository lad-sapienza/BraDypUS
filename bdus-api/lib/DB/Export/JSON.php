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

    /**
     * @deprecated Use Export::streamToResponse() instead.
     */
    public function saveToFile(array $data, array $metadata, string $file): bool
    {
        $file .= '.json';
        return (bool) file_put_contents($file, $this->render($data, $metadata));
    }
}
