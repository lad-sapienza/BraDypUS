<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;


interface ExportInterface
{
    public function saveToFile( array $data = [], array $metadata = [], string $file ) : bool;
}