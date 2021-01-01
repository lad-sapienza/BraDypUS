<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;


class XML
{
    public function saveToFile( array $data, array $metadata, string $file ) : bool
    {
        $file .= '.xml';

		$xml = [
            '<?xml version="1.0" encoding="utf-8" ?>',
            "<root>",
            "\t<metadata>",
            "\t\t<table>{$metadata['table']}</table>",
            "\t\t<filter>{$metadata['filter']}</filter>",
            "\t\t<filter_values>" . implode("</filter_values>\n\t\t<filter_values>", $metadata['filter_values']) . "</filter_values>",
            "\t</metadata>",
            "\t<data>"
        ];
        foreach ($data as $row) {
            array_push($xml, "\t\t<item>");
            foreach ($row as $key => $value) {
                array_push($xml, "\t\t\t<{$key}><![CDATA[{$value}]]></{$key}>");
            }
            array_push($xml, "\t\t</item>");
        }        
        array_push($xml, "\t</data>");
        array_push($xml, "</root>");

        return file_put_contents($file, implode("\n", $xml));
    }
}