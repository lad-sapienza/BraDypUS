<?php

namespace DB\Export;


interface ExportInterface
{
    public function saveToFile( array $data = [], array $metadata = [], string $file ) : bool;
}