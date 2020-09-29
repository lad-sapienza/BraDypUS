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

	public function list_all_backups()
	{
		$files = \utils::dirContent(PROJ_DIR . 'backups') ?: [];

		$data = [];
		foreach ($files as $file ) {
			$f_info = $this->getInfoFromFileName($file);
			$f_info['orig'] = $file;
			$f_info['full_orig'] = PROJ_DIR . 'backups/' . $file;
			$f_info['size'] = round ( filesize( PROJ_DIR . 'backups/' . $file )/1024/1024, 3 );
			array_push($data, $f_info);
		}

		$this->render('backup', 'list_all_backups', [
			'data' => $data,
			'engine' => $this->db->getEngine(),
			'canErase' => \utils::canUser('admin'),
			'canRestore' => \utils::canUser('super_admin') && $this->db->getEngine() !== 'pgsql'
		]);
	}

	public function restoreBackup()
	{
		try {
			$file = $this->get['file'];
			$f_info = $this->getInfoFromFileName($file);
			if ($f_info['engine'] !== $this->db->getEngine()){
				$this->response( "wrong_restore_engine", 'error', [ $f_info['engine'], $this->db->getEngine() ] );
				return;
			}

			$restore = new bigRestore( $this->db, ($this->db->getEngine() === 'sqlite') );
			$restore->runImport(PROJ_DIR . 'backups/' . $file);

			$this->response("ok_backup_restored", 'success');
		} catch (\Throwable $th) {
			$this->log->error($th);
			$this->response("error_backup_not_restored", 'error');
		}
		
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
						->setDumpBinaryPath('/Applications/Postgres.app/Contents/Versions/latest/bin/')
						->setDbName( $this->cfg->get('main.db_name') )
						->setUserName( $this->cfg->get('main.db_username') )
						->setPassword( $this->cfg->get('main.db_password') ) ;
						
					if ( null !== $this->cfg->get('main.db_host') && '' !== $this->cfg->get('main.db_host') ) {
						$bup->setHost( $this->cfg->get('main.db_host') );
					}
						
					break;

				case 'sqlite':
					$bup = Sqlite::create()
						->setDbName("./projects/" . $this->cfg->get('main.name') . "/db/bdus.sqlite");
					break;

				default:
					throw new \Exception('Unknown or unsupported database engine');
					break;
			}
			
			$bup->useCompressor(new GzipCompressor())
				->dumpToFile($file);
			
			$this->response('ok_backup', 'success');

		} catch(\Throwable $e) {
			$this->log->error($e);
			$this->response('error_backup', 'error');
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

		list($app, $engine, $date) = explode('-', $filename);

		$ret['app'] = $app;
		$ret['engine'] = $engine;
		$ret['time'] = $date;
		$ret['formatted_time'] = (new DateTime("@{$date}"))->format('c');

		return $ret;
	}
}
