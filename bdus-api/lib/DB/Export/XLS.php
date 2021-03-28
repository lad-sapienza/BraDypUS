<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Export;


class XLS
{
	protected $xls;

	public function saveToFile( array $data, array $metadata, string $file ) : bool
	{
		$file .= '.xls';
		
		$this->xls_writeLine(array_keys($data[0]));

		foreach ( $data as $row ) {
			$this->xls_writeLine($row);
		}

		$this->xls_close($file);

		return true;
	}

	private function xls_cell($value)
	{
		$value = utf8_decode($value);

		if (is_numeric($value)) {
			$this->xls['string'] .= pack("sssss", 0x203, 14, $this->xls['row'], $this->xls['col'], 0x0);

			$value = pack("d", $value);
		} else {
			$l = strlen($value);

			$this->xls['string'] .= pack("ssssss", 0x204, 8 + $l, $this->xls['row'], $this->xls['col'], 0x0, $l);
		}

		$this->xls['string'] .= $value;

		$this->xls['col']++;
	}

	private function xls_writeLine(array $arr) : void
	{
		foreach($arr as $data) {
			$this->xls_cell($data);
		}

		$this->xls_end_row();
	}
	
	private function xls_end_row()
	{
		$this->xls['row']++;
		$this->xls['col'] = 0;
	}

	private function xls_close(string $file)
	{
		$content = pack("ssssss", 0x809, 0x8, 0x0, 0x10, 0x0, 0x0);

		$content .= $this->xls['string'];

		$content .= pack("ss", 0x0A, 0x00);

		if(!$hndl = fopen($file, "w+")) {
			throw new \Exception("Can not open $file");
		}

		if(!fwrite($hndl, $content)) {
			throw new \Exception("Can not write in $file");
		}

		if(!fclose($hndl)) {
			throw new \Exception("Can not close $file");
		}
	}
}