<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;

interface ExportInterface
{
    /**
     * Renders the data to a string ready to be sent or written.
     */
    public function render(array $data, array $metadata): string;
}
