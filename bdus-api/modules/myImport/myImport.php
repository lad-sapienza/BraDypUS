<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 12, 2012
 */

class myImport_ctrl extends Controller
{
	public function button()
	{
		$db = new DB();
		
		switch ($db->getEngine())
		{
			case 'sqlite':
			case 'mysql':
				echo '<h3>' . tr::sget('importing_for_driver' , $db->getEngine() ) . '</h3>'
					. '<a class="import" href="javascript:void(0)" data-driver="' . $db->getEngine() . '">Import</a>';
				break;
				
			default:
				echo '<div class="text-error">'
					. '<strong>' . tr::get('attention') . ':</strong> ' . tr::sget('not_valid_import_driver', strtoupper($db->getEngine()))
					. '</div>';
				break;
		}
		
	}
	
	
	public function upload()
	{
		$driver = $this->get['driver'];
		
		switch($driver)
		{
			case 'mysql':
				$allowedExtensions = array('sql', 'gz');
				break;
			case 'sqlite':
				$allowedExtensions = array('db');
				break;
			default:
				break;
		}
		
		$file_ctrl = new file_ctrl($_GET, $_POST, $_REQUEST);
		$result = $file_ctrl->upload_args(false, true);
		
		if ($driver == 'sqlite')
		{
			self::importSqlite($result);
		}
		else
		{
			echo json_encode($result);
		}
	}
	
	
	public static function importSqlite($result)
	{
		try
		{
			$new_file = uniqid('bdus') . '.db';
				
			if ($result['success'])
			{
				if (!copy(PROJ_DIR . 'db/bdus.sqlite', PROJ_DIR . 'export/' . $new_file))
				{
					throw new myException('Can not make backup of existing database');
				}
		
				if (!copy(PROJ_TMP_DIR . $result['filename'] . '.' . $result['ext'], PROJ_DIR . 'db/bdus.sqlite'))
				{
					throw new myException('Can not move new file to db folder.');
				}
				echo json_encode(array('success'=>true, 'status'=>'success', 'text'=>tr::sget('ok_import_file_backup', $new_file)));
			}
			else
			{
				throw new myException('Can not uploaad file');
			}
		}
		catch (myException $e)
		{
			$e->log();
			utils::response('error_import', 'error');
		}
		
	}
	
	public function importMysql()
	{
		$filename = $this->get['filename'];
		$start = $this->get['start'];
		$offset = $this->get['offset'];
		$totalqueries = $this->get['totalqueries'];
		
		$a = new BigDump ();
	
		$res = $a->parseFile(new DB(), $filename, $start, $offset, $totalqueries);
	
		echo $a->getJson();
		
	}
}
