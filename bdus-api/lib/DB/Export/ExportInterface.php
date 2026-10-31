<?php
/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;

interface ExportInterface
{
    /**
     * Writes the rows to the stream $out one at a time, so memory use does not
     * grow with the size of the result.
     *
     * @param iterable<array> $rows     Associative rows (array or generator)
     * @param resource        $out      Writable stream
     * @throws \Exception 'Empty dataset' when requiresRows() and there are none
     */
    public function write(iterable $rows, array $metadata, $out): void;

    /** True when an empty result is an error rather than an empty file. */
    public function requiresRows(): bool;

    /**
     * True when write() can target the HTTP output directly. False when the
     * format can fail late (or needs a seekable file): the caller then writes to
     * a temporary stream first and sends headers only on success.
     */
    public function streamsDirectly(): bool;

    /**
     * Renders in-memory rows to a string. Convenience for small datasets and
     * tests; large exports use write().
     */
    public function render(array $data, array $metadata): string;
}
