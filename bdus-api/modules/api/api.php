<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 21, 2013
 *
 *
 * API is reacheable at {app_base_url}/api/{app_name}/{no_prefix_tb_name}
 *  eg.: http://db.bradypus.net/sitarc/siti
 *
 * Available parameters:
 * GET:
 *  app:  string,  required, application name (eg: sitarc)
 *  tb:   string, required, no-prefix table name (eg: siti)
 *  records_per_page: int, optional, default: 30 number of records to show in each page.
 *
 *  id:   int, (database) ID for single record. Only one record will be returned
 *
 *
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

	/**
	 * [run_v2 description]
	 * 		api/app/tb?
	 * 			verb:  Required. One of: read | edit
	 * 			id: required for verb read and for verb search, type id_array
	 *
	 * 			type: required for verb search. One of: all | recent | advanced | sqlExpert | fast | id_array | encoded
	 *
	 * 			limit: optional for type recent, default: 20
	 * 			adv (post): required for type advanced
	 * 			join: optional, for type sqlExpert
	 * 			querytext: required for type sqlExpert
	 * 			string: required for type fast
	 *
	 * 			records_per_page: optional for type search, used for pagination, default: 30
	 *
	 * @return [type] [description]
	 */
	public function run()
	{

		try {

			$request = [];

			// Get params
			$app = $this->get['app'];
			$request['tb'] = $this->get['app'] . '__' . $this->get['tb'];
			$verb = $this->get['verb'];

			$id = $this->get['id']; // required for verb read

			$type = $this->get['type']; // required for verb search


			// Validate verb
			$valid_verbs = ['read', 'search'];
			if (!$verb || !in_array($verb, $valid_verbs)) {
				throw new Exception("Invalid verb {$verb}. Verb must be one of " . implode(', ', $valid_verbs));
			}
			// Validate app
			$valid_apps = utils::dirContent(PROJS_DIR);
			if (!$app || !in_array($app, $valid_apps)) {
				throw new Exception("Invalid app {$app}. App must be one of " . implode(', ', $valid_apps));
			}

			// READ
			if ($verb === 'read') {
				if (!$id) {
					throw new Exception("Parameter id is required with verb read");
				}
				return $this->array2json(
					$this->getOne($request['tb'], $id)
				);
			}

			// SEARCH
			if ($verb === 'search') {



				$valid_types = ['all', 'recent', /*'advanced', */'sqlExpert', 'fast', 'id_array', 'encoded'];

				$request['type'] = $type;

				switch ($request['type']) {
					case 'all':
						break;

					case 'recent':
						$request['limit'] = $this->get['limit'] ? $this->get['limit'] : 20;
						break;

					// case 'advanced':
					// 	$request['adv'] = $this->post['adv'];
					// 	if (!$request['adv'] || !is_array($request['adv']) ) {
					// 		throw new Exception("Parameter adv (POST, array) is required for type advanced");
					// 	}
					// 	break;

					case 'sqlExpert':
						$request['join'] = $this->get['join'];
						$request['querytext'] = $this->get['querytext'];
						$request['fields'] = $this->request['fields'];
						if (!$request['querytext']) {
							throw new Exception("Parameter querytext is required for type sqlExpert");
						}
						break;

					case 'fast':
						$request['string'] = $this->get['string'];
						if (!$request['string']) {
							throw new Exception("Parameter string is required for type fast");
						}
						break;

					case 'id_array':
						$request['id'] = $this->get['id'];
						if (!$request['id']) {
							throw new Exception("Parameter id is required for type id_array");
						}
						break;

					case 'encoded':
						$request['q_encoded'] = $this->get['q_encoded'];
						$request['join'] = $this->get['join'];
						$request['fields'] = $this->get['fields'];
						if (!$this->get['q_encoded']) {
							throw new Exception("Parameter q_encoded is required for type encoded");
						}
						break;

					default:
						throw new Exception("Invalid search type {$type}. Type must be one of " . implode(', ', $valid_types));
						break;
				}

			}

			$records_per_page = $this->get['records_per_page'] ? $this->get['records_per_page'] : 30;

			$query = new Query(new DB, $request);

			$header['query_arrived'] = $query->getQuery();
			$header['query_encoded'] = base64_encode($query->getQuery());
			$header['total_rows'] = $this->get['total_rows'] ? $this->get['total_rows'] : (int) $query->getTotal();
			$header['page'] = $this->get['page'] ? $this->get['page'] : 1;
			$header['total_pages'] = ceil($header['total_rows']/$records_per_page);
			$header['table'] = $request['tb'];
			$header['stripped_table'] = str_replace($_SESSION['app'] . '__', null, $request['tb']);

			if ($header['page'] > $header['total_pages']) {
				$header['page'] = $header['total_pages'];
			}

			if ($header['total_rows'] > 0) {
				$query->setLimit(($header['page'] -1) * $records_per_page, $records_per_page);
			}

			$header['no_records_shown'] = (int) $query->getTotal();

			$header['query_executed'] = $query->getQuery();

			$header['fields'] = $query->getFields();

			$this->array2json([
				'head' => $header,
				'records' => $query->getResults(empty($this->request['fields']))
			]);

		} catch (Exception $e) {

			$this->array2json(array('type' => 'error', 'text' => $e->getMessage()));

		}
	}

	private function getCustom($tb, $fields = "*", $join = false, $where = false, $order = false, $limit = false, $group = false)
	{
		$sql = 'SELECT ';
		if (is_array($fields)) {
			foreach ($fields as $key => $value) {
				$f[] = $value . ( is_string($key) ? ' as ' . $key : '');
			}
			$sql .= implode(', ', $f) . ' ';
		} else {
			$sql .= $fields . ' ';
		}

		$sql = 'FROM ' . $tb . ' '
			. ($join ? $join . ' ': '')
			. 'WHERE ' . ($where ? $where : '1') . ' ';


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

	private function array2json($data, $dont_print = false)
	{
		$json = json_encode($data, (version_compare(PHP_VERSION, '5.4.0') >=0 ? JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE : false));

		if ($dont_print)
		{
			return $json;
		}
		else
		{
			header('Access-Control-Allow-Origin: *');
			header('Content-type: application/json; charset=utf-8');
			echo $json;
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
