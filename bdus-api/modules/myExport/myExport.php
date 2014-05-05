<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2012
 */

class myExport_ctrl
{
	/**
	 * Returns HTML with folder content
	 */
	public static function getContent()
	{
		$content = utils::dirContent(PROJ_EXP_DIR);
		
		if (is_array($content))
		{
			$html = '<table class="table table-striped table-bordered">';
			foreach($content as $file)
			{
				$html .= '<tr>'
				. '<td>' . $file . '</td>'
				. '<td>' . round ( filesize( PROJ_EXP_DIR . $file )/1024/1024, 3 ) . ' MB</td>'
				. '<td><button class="download btn btn-primary" data-file="' . PROJ_EXP_DIR . $file . '"><i class="glyphicon glyphicon-download-alt"></i> ' . tr::get('download') . '</button> '
				. (utils::canUser('edit') ? '<button type="button" class="erase btn btn-danger" data-file="' . $file . '"><i class="glyphicon glyphicon-trash"></i> ' . tr::get('erase') . '</button>' :  '') . '</td>'
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
	public static function doExport($tb, $format, $sql = false)
	{
		try
		{
			$where = $sql ? base64_decode($sql) : '1';
		
			$file = PROJ_EXP_DIR . $tb . '.' . date('U');
		
			$export_handle = new Export(new DB(), $file, $tb, $where);
		
			$export_handle->doExport($format);
		
			$resp['text'] = tr::get('export_success');
			$resp['status'] = 'success';
		}
		catch(myException $e)
		{
			$e->log();
			$resp['text'] = tr::get('export_error') . tr::get('details_in_log');
			$resp['status'] = 'error';
		}
		
		echo json_encode($resp);
	}
	
	/**
	 * Erases exported file and return json
	 * @param unknown_type $file
	 * @throws myException
	 */
	public static function erase($file)
	{
		try
		{
			$a = @unlink(PROJ_EXP_DIR . $file);
		
			if (!$a)
			{
				throw new myException(tr::sget('error_erasing_file', $file));
			}
			$resp['text'] = tr::sget('success_erasing_file', $file);;
			$resp['status'] = 'success';
		}
		catch(myException $e)
		{
			$resp['text'] = $e->getMessage();
			$resp['status'] = 'error';
		}
		
		echo json_encode($resp);
	}
}