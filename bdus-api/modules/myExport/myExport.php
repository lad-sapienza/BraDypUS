<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 10, 2012
 */

use DB\Export\Export;

class myExport_ctrl extends Controller
{
	/**
	 * Returns HTML with folder content
	 */
	public static function getContent()
	{
		$content = \utils::dirContent(PROJ_DIR . 'export/');
		
		if (is_array($content)) {
			$html = '<table class="table table-striped table-bordered">';
			foreach($content as $file)
			{
				$html .= '<tr>'
				. '<td>' . $file . '</td>'
				. '<td>' . round ( filesize( PROJ_DIR . 'export/' . $file )/1024/1024, 3 ) . ' MB</td>'
				. '<td><button class="download btn btn-primary" data-file="' . PROJ_DIR . 'export/' . $file . '"><i class="fa fa-download"></i> ' . \tr::get('download') . '</button> '
				. (\utils::canUser('edit') ? '<button type="button" class="erase btn btn-danger" data-file="' . $file . '"><i class="fa fa-trash"></i> ' . \tr::get('erase') . '</button>' :  '') . '</td>'
				.'</tr>';
			}
			$html .= '</table>';
			
			echo $html;
		}
	}
	
	/**
	 * Expoerts data and returns json
	 * @param string $tb	table name to export
	 * @param string $format	exporting format
	 * @param string $sql	sql query to export
	 */
	public function doExport()
	{
		$tb = $this->get['tb'];
		$format = $this->get['format'];
		$obj_encoded = $this->get['obj_encoded'];

		try {
			list($where, $values) = \SQL\SafeQuery::decode($obj_encoded);

			$where = $where ?: '1=1';
		
			$file = PROJ_DIR . 'export/' . $tb . '.' . date('U');

			$exp = new Export($this->db, $tb, $where, $values);

			if ($exp->saveToFile($format, $file)) {
				$this->response('export_success', 'success');
			} else {
				$this->response('export_error', 'error');
			}
			return;
		
		} catch(\Throwable $e) {
			$this->log->error($e);
			$this->response('export_error', 'error');
		}
	}
	
	/**
	 * Erases exported file and return json
	 * @throws Exception
	 */
	public function erase()
	{
		$file = $this->get['file'];
		try
		{
			$a = @unlink(PROJ_DIR . 'export/' . $file);
		
			if (!$a){
				throw new \Exception(\tr::get('error_erasing_file', [$file]));
			}
			$this->response('success_erasing_file', 'success', [$file]);
		} catch(\Exception $e) {
			$this->response($e->getMessage(), 'error');
		}
	}
}