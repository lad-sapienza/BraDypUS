<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2012
 */
use \Spatie\DbDumper\Databases\Sqlite;
use \Spatie\DbDumper\Databases\MySql;
use \Spatie\DbDumper\Databases\PostgreSql;
use \Spatie\DbDumper\Compressors\GzipCompressor;

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
			$res = @unlink(PROJ_DIR . 'backups/' . $file);

			if (!$res){
				throw new \Exception(\tr::get('error_erasing_file', [$file]));
			}
			$resp['text'] = \tr::get('success_erasing_file', [$file]);
			$resp['status'] = 'success';
		} catch(\Exception $e) {
			$resp['text'] = $e->getMessage();
			$resp['status'] = 'error';
		}
		$this->returnJson($resp);
	}

	public function getSavedBups()
	{
		$content = \utils::dirContent(PROJ_DIR . 'backups');

        if (!is_array($content)) {
			echo '<h2>' . \tr::get('no_bup_present') . '</h2>';
			return;
		}

		$html = '<table class="table table-hover table-bordered table-striped">';
		$html .= '<thead><tr>'
			. '<th>App</th>'
			. '<th>Engine</th>'
			. '<th>Time</th>'
			. '<th>Size</th>'
			. '<th></th>'
		.'</tr></thead>';
		foreach($content as $file) {
			$info = $this->getInfoFromFileName($file);
			$html .= '<tr>'
			. '<td>' . strtoupper($info['app']) . '</td>'
			. '<td>' . $info['driver'] . '</td>'
			. '<td>' . $info['formatted_time'] . '</td>'
			. '<td>' . round ( filesize( PROJ_DIR . 'backups/' . $file )/1024/1024, 3 ) . ' MB</td>'
			. '<td>'
				. '<div class="btn-group">'
					.'<button class="download btn btn-info" onclick="backup.download(\'' . PROJ_DIR . 'backups/' . $file . '\')"><i class="glyphicon glyphicon-download-alt"></i> ' . \tr::get('download') . '</button>'
					. (\utils::canUser('edit') ? ' <button type="button" class="btn btn-danger" onclick="backup.erase(\'' . $file . '\', this)"><i class="glyphicon glyphicon-trash"></i> ' . \tr::get('erase') . '</button>' :  '')
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
			$file = $this->setFileName();

			switch($this->db->getEngine()) {
				case 'mysql':

					$bup = MySQL::create()
						->setDbName( $this->cfg->get('main.db_name') )
						->setUserName( $this->cfg->get('main.db_username') )
						->setPassword( $this->cfg->get('main.db_password') ) ;
						
					if ( null !== $this->cfg->get('main.db_host') && '' !== $this->cfg->get('main.db_host') ) {
						$bup->setHost( $this->cfg->get('main.db_host') );
					}
						
					break;

				case 'pgsql':
					$bup = PostgreSql::create()
						->setDbName($this->cfg->get('main.db_name'))
						->setUserName($d['db_username'])
						->setPassword($d['db_password']);
						
					if (isset($d['db_host']) && $d['db_host'] !== '') {
						$bup->setHost( $d['db_host'] );
					}
						
					break;

				case 'sqlite':
					$bup = Sqlite::create()
						->setDbName("./projects/" . $this->cfg->get('main.name') . "/db/bdus.sqlite");
					break;

				default:
					throw new \Exception('Unknown or unsupported database driver');
					break;
			}
			
			$bup->useCompressor(new GzipCompressor())
				->dumpToFile($file);
			
			\utils::response('ok_backup', 'success');

		} catch(\Throwable $e) {
			$this->log->error($e);
			\utils::response('error_backup', 'error');
		}
	}

	private function setFileName(): string
	{
		return $file = implode( '', [
			'projects/', 
			$this->cfg->get('main.name'),
			'/backups/',
			$this->cfg->get('main.name'),
			'-',
			$this->cfg->get('main.db_engine'),
			'-',
			(new DateTime())->getTimestamp(),
			'.sql.gz'
		]);
	}

	private function getInfoFromFileName( string $filename) : array
	{
		$filename = trim($filename);
		$ret = [];
		if( preg_match('/\.sql\.gz$/', $filename)) {
			$ret['gz'] = true;
			$filename = str_replace('.sql.gz', '', $filename);
		} elseif( preg_match('/\.sql$/', $filename)) {
			$filename = str_replace('.sql.gz', '', $filename);
		} else {
			throw new \Exception("Filename `$filename` MUST end in .sql or .sql.gz");
		}

		list($app, $driver, $date) = explode('-', $filename);

		$ret['app'] = $app;
		$ret['driver'] = $driver;
		$ret['time'] = $date;
		$ret['formatted_time'] = (new DateTime("@{$date}"))->format('c');

		return $ret;
	}
}
