<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2012
 */


class backup_ctrl extends Controller
{
	/**
	 * Erases backup files and return json
	 * @param string $file	filename to erase
	 * @throws \Exception
	 */
	public function deleteBackup()
	{
		try
		{
			$file = $this->get['file'];
			if (!$file){
				throw new Exception("Missing parameter file");
			}
			$a = @unlink(PROJ_DIR . 'backups/' . $file);

			if (!$a){
				throw new \Exception(tr::get('error_erasing_file', [$file]));
			}
			$resp['text'] = tr::get('success_erasing_file', [$file]);
			$resp['status'] = 'success';
		} catch(\Exception $e) {
			$resp['text'] = $e->getMessage();
			$resp['status'] = 'error';
		}
		$this->returnJson($resp);
	}

	public function getSavedBups()
	{
		$content = utils::dirContent(PROJ_DIR . 'backups');

        if (!is_array($content)) {
			echo '<h2>' . tr::get('no_bup_present') . '</h2>';
			return;
        }

		$html = '<table class="table table-hover table-bordered table-striped">';
		foreach($content as $file) {
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


	public function doBackup()
	{
		try {
			$file = PROJ_DIR . 'backups/' . APP . '_' . date('Y-m-d_H-i-s');

			switch($this->db->getEngine()) {
				case 'mysql':
					$bupMysql = new BackupMySQL($this->db, $file . '.sql');
					$bupMysql->exportAll();
					break;

				case 'sqlite':
					copy(PROJ_DIR . 'db/bdus.sqlite', $file . '.db');
					break;

				default:
					throw new \Exception('Unknown or unsupported database driver');
					break;
			}

		} catch(\Exception $e) {
			echo 'error';
			$this->log->error($e);
		}
	}
}
