<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2012
 */


class backup_ctrl
{
	/**
	 * Erases backup files and return json
	 * @param string $file	filename to erase
	 * @throws myException
	 */
	public static function erase($file)
	{
		try
		{
			$a = @unlink(PROJ_DIR . 'backups/' . $file);

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

	public static function getSavedBups()
	{
		$content = utils::dirContent(PROJ_DIR . 'backups');

		if (is_array($content))
		{
			$html = '<table class="table table-hover table-bordered table-striped">';
			foreach($content as $file)
			{
				$html .= '<tr>'
				. '<td>' . $file . '</td>'
				. '<td>' . round ( filesize( PROJ_DIR . 'backups/' . $file )/1024/1024, 3 ) . ' MB</td>'
				. '<td>'
					. '<div class="btn-group">'
						.'<button class="download btn btn-info" onclick="backup.download(\'' . PROJ_DIR . 'backups/' . $file . '\')"><i class="glyphicon glyphicon-download-alt"></i> ' . tr::get('download') . '</button>'
						. (utils::canUser('edit') ? ' <button type="button" class="btn btn-danger" onclick="backup.erase(\'' . $file . '\', this)"><i class="glyphicon glyphicon-trash"></i> ' . tr::get('erase') . '</button>' :  '')
					. '</div>'
				. '</td>'
				.'</tr>';
			}
			$html .= '</table>';

			echo $html;
		}
		else
		{
			echo '<h2>' . tr::get('no_bup_present') . '</h2>';
		}
	}


	public static function doBackup()
	{
		try
		{
			$file = PROJ_DIR . 'backups/' . APP . '_' . date('Y-m-d_H-i-s');

			$db = new DB();

			switch($db->getEngine())
			{
				case 'mysql':
					$bupMysql = new BackupMySQL(new DB(), $file . '.sql');
					$bupMysql->exportAll();
					break;

				case 'sqlite':
					copy(PROJ_DIR . 'db/bdus.sqlite', $file . '.db');
					break;

				default:
					throw new myException('Unknown or unsupported database driver');
					break;
			}

		}
		catch(myException $e)
		{
			echo 'error';
			$e->log();
		}
	}
}
