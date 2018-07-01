<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 21, 2013
 *
 *
 * API is reacheable at {app_base_url}/api/{app_name}/{no_prefix_tb_name}
 *  eg.: https://db.bradypus.net/sitarc/siti
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


	/**
	 * [run_v2 description]
	 * 		api/app/tb?
	 * 			verb:  Required. One of: read | edit | inspect
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

			// Initialize array of request data
			$request = [];

			// Check all input data

			// $_GET['app']: mandatory
			$valid_apps = utils::dirContent(PROJS_DIR);
			if (!$this->get['app'] || !in_array($this->get['app'], $valid_apps)) {
				throw new Exception("Invalid app {$this->get['app']}. App must be one of " . implode(', ', $valid_apps));
			}
			$app = $this->get['app'];


			// $_GET['tb']: mandatory
			if (!$this->get['tb']) {
				throw new Exception("Tb parameter is mandatory");
			}
			if (strpos($this->get['tb'], $app . '__') === false) {
		    $request['tb'] = $app . '__' . $this->get['tb'];
			} else {
				$request['tb'] = $this->get['tb'];
			}


			// $_GET['verb']: mandatory, one of read, search
			$valid_verbs = ['read', 'search', 'inspect'];
			if (!$this->get['verb'] || !in_array($this->get['verb'], $valid_verbs)) {
				throw new Exception("Invalid verb {$this->get['verb']}. Verb must be one of " . implode(', ', $valid_verbs));
			}


			// READ verb
			if ($this->get['verb'] === 'read') {
				if (!$this->get['id']) {
					throw new Exception("Parameter id is required with verb read");
				}
				return $this->array2response(
					$this->getOne($request['tb'], $this->get['id'])
				);
			}

			// INSPECT verb
			if ($this->get['verb'] === 'inspect') {

				if ($request['tb'] === PREFIX . '__all') {
					$ret = [];

					foreach (cfg::tbEl('all', 'all') as $t) {
						$stripped_name = str_replace(PREFIX . '__', null, $t['name']);
						foreach (cfg::fldEl($t['name']) as $f){
							$t['fields'][$f['name']] = $f;
						}
						$ret[$stripped_name] = $t;
					}
					return $this->array2response( $ret );
				}
				$ret = [];
				$flds = cfg::fldEl($request['tb']);

				foreach ($flds as $f) {
					$f['fullname'] = $request['tb'] . ':' . $f['name'];
					array_push($ret, $f);
				}
				foreach (cfg::tbEl($request['tb'], 'plugin') as $plg) {
					foreach (cfg::fldEl($plg) as $f) {
						$f['fullname'] = $plg . ':' . $f['name'];
						$f['label'] = cfg::tbEl($plg, 'label') . ': ' . $f['label'];
						array_push($ret, $f);
					}
				}

				return $this->array2response( $ret );
			}


			// SEARCH verb
			if ($this->get['verb'] === 'search') {

				$valid_types = ['all', 'recent', 'advanced', 'sqlExpert', 'fast', 'id_array', 'encoded'];

				if( !in_array($this->get['type'], $valid_types)) {
					throw new Exception("Invalid search type {$this->get['type']}. Type must be one of " . implode(', ', $valid_types));
				}
				$request['type'] = $this->get['type'];

				// Set query parameters

				switch ($request['type']) {
					case 'all':
						// No params set
						break;

					case 'recent':
						$request['limit'] = $this->get['limit'] ? $this->get['limit'] : 20;
						break;

					case 'advanced':
						$request['adv'] = $this->get['adv'];
						if (is_string($request['adv'])) {
							parse_str($request['adv'], $request['adv']);
						}
						if (!$request['adv'] || !is_array($request['adv']) ) {
							throw new Exception("Parameter adv (POST, array) is required for type advanced");
						}
						break;

					case 'sqlExpert':
						if (!$this->get['querytext']) {
							throw new Exception("Parameter querytext is required for type sqlExpert");
						}
						$request['querytext'] = $this->get['querytext'];
						$request['join'] = $this->get['join'];
						$request['fields'] = $this->request['fields'];
						break;

					case 'fast':
						$request['string'] = $this->get['string'];
						if (!$request['string']) {
							throw new Exception("Parameter string is required for type fast");
						}
						break;

					case 'id_array':
						if (!$this->get['id']) {
							throw new Exception("Parameter id is required for type id_array");
						}
						$request['id'] = $this->get['id'];
						break;

					case 'encoded':
						if (!$this->get['q_encoded']) {
							throw new Exception("Parameter q_encoded is required for type encoded");
						}
						$request['q_encoded'] = $this->get['q_encoded'];
						$request['join'] = $this->get['join'];
						$request['fields'] = $this->get['fields'];
						$request['limit_start'] = $this->get['limit_start'];
						$request['limit_end'] = $this->get['limit_end'];
						$request['group'] = $this->get['group'];
						break;
				}
			}

			$query = new Query(new DB, $request);

			// Set Header
			$header['query_arrived'] = $query->getQuery();
			$header['query_encoded'] = base64_encode($query->getQuery());
			$header['total_rows'] = $this->get['total_rows'] ? $this->get['total_rows'] : (int) $query->getTotal();
			$header['page'] = ($this->get['page'] && (int)$this->get['page'] > 0) ? (int)$this->get['page'] : 1;

			$records_per_page = $this->get['records_per_page'] ? $this->get['records_per_page'] : 30;
			$header['total_pages'] = ceil($header['total_rows']/$records_per_page);
			$header['table'] = $request['tb'];
			$header['stripped_table'] = str_replace($app . '__', null, $request['tb']);
			$header['table_label'] = cfg::tbEl($request['tb'], 'label');
			$header['page'] = ($header['page'] > $header['total_pages']) ? $header['total_pages'] : $header['page'];

			if ($request['limit_end']) {
				$query->setLimit($request['limit_start'], $request['limit_end']);
			} else if ($header['total_rows'] > 0) {
				$query->setLimit(($header['page'] -1) * $records_per_page, $records_per_page);
			}

			$header['no_records_shown'] = (int) $query->getTotal();
			$header['query_executed'] = $query->getQuery();
			$header['fields'] = $query->getFields();

			if ($this->get['geojson']) {
				return $this->array2response(
					toGeoJson::fromMultiArray(
						$query->getResults( ($this->get['fullRecords'] && $this->get['fullRecords'] !== 'false') ), true
					)
				);
			} else {
				return $this->array2response([
					'head' => $header,
					'records' => $query->getResults( ($this->get['fullRecords'] && $this->get['fullRecords'] !== 'false') )
				]);
			}

		} catch (Exception $e) {
			return $this->array2response([
				'type' => 'error',
				'text' => $e->getMessage()
				]);
		}
	}

	/**
	 * Return full array with record data
	 * @param  string $tb table name
	 * @param  int $id record id
	 * @return array     array with all data
	 */
	private function getOne($tb, $id)
	{
		$rec = new Record($tb, $id, new DB);
		$data['metadata'] = [
			'table' => $tb,
			'stripped_table' => str_replace($this->get['app'] . '__', null, $tb),
			'table_label' => cfg::tbEl($tb, 'label')
		];
    $data['fields'] = cfg::fldEl($tb, 'all', 'label');
		$data['core'] = $rec->getCore();
		$data['coreLinks'] = $rec->getCoreLinks();
		$data['allPlugins'] = $rec->getAllPlugins();
		$data['fullFiles'] = $rec->getFullFiles();
		$data['geodata'] = $rec->getGeodata();
    if (cfg::tbEl($tb, 'rs')){
      $data['rs'] = $rec->getRS();
    }
		$data['userLinks'] = $rec->getUserLinks();
		return $data;
	}

	/**
	 * Formats array data as pretty-printed JSON and returns or echoes (with Cross-domain alloe policy) it
	 * @param  array  $data       array of input data
	 * @return string
	 */
	private function array2response($data)
	{
		$mime = 'application/json';

		if ( $data['type'] !== 'error' && $data['records'] && file_exists("projects/{$this->get['app']}/mods/api/PostProcess.php")) {
			require_once("projects/{$this->get['app']}/mods/api/PostProcess.php");
			$postProcess = new PostProcess();

			$resp = $postProcess->run($data, $this->get, $this->post);

			$data = $resp['data'];
			$mime ?: $resp['mime'];
		}
		
		if (is_array($data)) {
			$data = json_encode($data, (version_compare(PHP_VERSION, '5.4.0') >=0 ? JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE : false));
		}

		header('Access-Control-Allow-Origin: *');
		header('Content-type: ' . $mime . '; charset=utf-8');
		echo $data;


	}

}
