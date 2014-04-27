<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2013
 * @license			All rights reserved
 * @since			Apr 21, 2013
 */

class api_ctrl extends Controller
{
	private function buildHeader(Query $query, $tb)
	{
		$header = array();
		
		$header['query_arrived'] = $query->getQuery();
		$header['query_encoded'] = base64_encode($query->getQuery());
		$header['status'] = 'success';
		$header['total_rows'] = (int) $query->getTotal();
		$header['fields'] = cfg::fldEl($tb, 'all', 'label');
		
		return $header;
	}
	
	
	public function run()
	{
		/**
		 * 	GET:
		 * 		page?
		 * 		total_rows?
		 * 
		 */
		try
		{
			if (!utils::canUser('read'))
			{
				$this->log();
			}
			$tb = $this->get['tb'] ? $_SESSION['app'] . '__' . $this->get['tb'] : '';
			
			if($tb && $this->get['id'] && is_string($this->get['id']))
			{
				$data = $this->getOne($tb, $this->get['id']);
				
				$this->array2jsonp('jsonCallback', $data);
			}
			else
			{
				$records_per_page = 30;
				
				$this->request['tb'] = $tb;
				
				$query = new Query(new DB, $this->request);
				
				$header['query_arrived'] = $query->getQuery();
				$header['query_encoded'] = base64_encode($query->getQuery());
				$header['total_rows'] = $this->get['total_rows'] ? $this->get['total_rows'] : (int) $query->getTotal();
				$header['page'] = $this->get['page'] ? $this->get['page'] : 1;
				$header['total_pages'] = ceil($header['total_rows']/$records_per_page);
				$header['table'] = $tb;
				$header['stripped_table'] = str_replace($_SESSION['app'] . '__', null, $tb);
				
				if ($header['page'] > $header['total_pages'])
				{
					$header['page'] = $header['total_pages'];
				}
				 
				if ($header['total_rows'] > 0)
				{
					$query->setLimit(($header['page'] -1) * $records_per_page, $records_per_page);
				}
				
				$header['no_records_shown'] = $query->getTotal();
				
				$header['query_executed'] = $query->getQuery();
				
				$header['fields'] = cfg::fldEl($tb, 'all', 'label');
        
				$this->array2jsonp(
						'jsonCallback',
						array(
								'head' => $header,
								'records' => $query->getResults(true)
								)
						);
			}
			
		}
		catch (myException $e)
		{
			echo json_encode(array('type' => 'error', 'text' => $e->getMessage()));
		}
	}
	
	
	private function getOne($tb, $id)
	{
		$rec = new Record($tb, $id, new DB);
		
    $data['fields'] = cfg::fldEl($tb, 'all', 'label');
    
		$data['core'] = $rec->getCore();
		
		$data['coreLinks'] = $rec->getCoreLinks();
		
		$data['allPlugins'] = $rec->getAllPlugins();
		
		$data['fullFiles'] = $rec->getFullFiles();
		
		$data['geodata'] = $rec->getGeodata();
		
    if (cfg::tbEl($tb, 'rs'))
    {
      $data['rs'] = $rec->getRS();
    }
		
		$data['userLinks'] = $rec->getUserLinks();
		
		return $data;
	}
	
	private function array2jsonp($callback, $data)
	{
		$json = json_encode($data, (version_compare(PHP_VERSION, '5.4.0') >=0 ? JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE : false));
		
		header('Content-type: application/json; charset=utf-8');
		echo $callback . '(' . $json . ')';
	}
	
	private function array2json($data, $dont_print = false)
	{
		$json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
		
		if ($dont_print)
		{
			return $json;
		}
		else
		{
			header('Content-type: application/json');
			echo json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
		}
	}
	
	private function log()
	{
		if (!$this->get['app'])
		{
			throw new myException('No application defined');
		}
		
		if (!file_exists(PROJS_DIR . $this->get['app'] . '/cfg/app_data.json'))
		{
			throw new myException('No app_data config file found!');
		}
		
		$app_data = json_decode(file_get_contents(PROJS_DIR . $this->get['app'] . '/cfg/app_data.json'), true);
			
		if (!$app_data['api_login_as_user'])
		{
			throw new myException('API login disabled for app: ' . $this->get['app']);
		}
			
		$user = new User(new DB($this->get['app']));
			
		$logged = $user->login(false, false, false, $app_data['api_login_as_user']);
			
		if (!$logged)
		{
			throw new myException('Error loging in as user id:' . $app_data['api_login_as_user']);
		}
			
		if (!utils::canUser('read'))
		{
			throw new myException('User logged, but don\'t have read privilege');
		}
		
	}
}