<?php

namespace DB\Export;


class HTML
{
    public function saveToFile( array $data, array $metadata, string $file ) : bool
    {
        $file .= '.html';

		$html = [
            "<!DOCTYPE html>",
            "<html>",
            "<head>",
            "\t<title>Export table " . $metadata['table'] . "</title>",
            "</head>",
            "<body>",
            "\t<table>",
            
            "\t\t<caption>Table: {$metadata['table']} / Filter: {$metadata['filter']} / Filter values: {$metadata['filter_values']}</caption>",
            
			"\t\t<thead>",
			"\t\t\t<tr>",
			"\t\t\t\t<th>" . implode("</th>\n\t\t\t\t<th>", $data[0]) . "</th>",
			"\t\t\t</tr>",
			"\t\t</thead>",
            "\t\t<tbody>"
        ];
        foreach ($data as $row) {
            array_push($html, "\t\t\t</tr>");

            foreach ($row as $key => $value) {
                array_push($html, "\t\t\t\t<td>" . nl2br($value) . "</td>");
            }
            array_push($html, "\t\t\t</tr>");
        }
        array_push($html, "\t\t</tbody>");
        array_push($html, "\t</table>");
        array_push($html, "</body>");
        array_push($html, "</html>");

        return file_put_contents($file, implode("\n", $html));
    }
}