<?php
/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;

/** Implements ExportInterface::render() on top of write(). */
trait RenderViaWrite
{
    public function render(array $data, array $metadata): string
    {
        $fh = fopen('php://temp', 'r+');
        try {
            $this->write($data, $metadata, $fh);
            rewind($fh);
            return (string) stream_get_contents($fh);
        } finally {
            fclose($fh);
        }
    }
}
