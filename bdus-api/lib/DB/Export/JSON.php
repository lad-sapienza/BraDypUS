<?php
/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;

class JSON implements ExportInterface
{
    use RenderViaWrite;

    public function requiresRows(): bool
    {
        return false;
    }

    public function streamsDirectly(): bool
    {
        return true;
    }

    /**
     * Writes {"metadata": …, "data": [ … ]}, pretty-printed, one row at a time.
     * The bytes are the same as json_encode(['metadata'=>…, 'data'=>…],
     * JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) would give for the whole.
     */
    public function write(iterable $rows, array $metadata, $out): void
    {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

        fwrite($out, "{\n    \"metadata\": " . $this->indent(json_encode($metadata, $flags), 4) . ",\n    \"data\": ");

        $n = 0;
        foreach ($rows as $row) {
            // Bad UTF-8 in one cell must not truncate a response already started.
            $json = json_encode($row, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
            fwrite($out, ($n++ ? ",\n" : "[\n") . '        ' . $this->indent($json, 8));
        }

        fwrite($out, $n ? "\n    ]\n}" : "[]\n}");
    }

    /** Indents every line but the first by $spaces. */
    private function indent(string $json, int $spaces): string
    {
        return str_replace("\n", "\n" . str_repeat(' ', $spaces), $json);
    }
}
