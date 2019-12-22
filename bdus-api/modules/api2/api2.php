<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since				Jul 02, 2018
 *
 *  Charts:
 *  /api/v2/{app}?verb=getChart(&id={chart-id})(&pretty=1)
 *
 * 	Unique Values (null and empty values excluded!)
 * 	/api/v2/{app}?verb=getUniqueVal&tb={tb-name-no-prefix}&fld={fld-name})(&pretty=1)
 *
 *  /api/v2/{app}?verb=read&tb={tb-name-no-prefix}&id={record-id}(&pretty=1)
 *  /api/v2/{app}?verb=inspect(&pretty=1)
 *  /api/v2/{app}?verb=inspect&tb={tb-name-no-prefix}(&pretty=1)
 *  /api/v2/{app}?verb=getVocabulary&voc={vocabulary-name}(&pretty=1)
 */

class api2 extends Controller
{
	private $app;
	private $verb;
	private $pretty = false;
	private $valid_verbs = [
		'read', 
		'search', 
		'inspect', 
		'getChart', 
		'getUniqueVal', 
		'getVocabulary'
	];
	private $not_valid_tables = [
		PREFIX . "queries",
		PREFIX . "rs",
		PREFIX . "userlinks",
		PREFIX . "users",
		PREFIX . "charts"
	];

	/**
	 * Main validation & variable setting
	 */
	private function setOrDie()
	{
		$this->pretty = $this->get['pretty'];
		// Validate APP
		$this->app = $this->get['app'];
		$valid_apps = utils::dirContent(PROJS_DIR);
		if (!$this->app || !in_array($this->app, $valid_apps)) {
			throw new Exception("Invalid app {$this->app}. App must be one of " . implode(', ', $valid_apps));
		}

		// Validate verb
		$this->verb = $this->get['verb'];

		if (!$this->verb || !in_array($this->verb, $this->valid_verbs)) {
			throw new Exception("Invalid verb {$this->verb}. Verb must be one of " . implode(', ', $this->valid_verbs));
		}

		// getOne
		if ($this->verb === 'read') {
			if (!$this->get['tb']) {
				throw new Exception("Table name is required with verb read");
			}
			if (!$this->get['id']) {
				throw new Exception("Record id is required with verb read");
			}
		}

		// getUniqueVal
		if ($this->verb === 'getUniqueVal') {
			if (!$this->get['tb']) {
				throw new Exception("Table name is required with verb getUniqueVal");
			}
			if (!$this->get['fld']) {
				throw new Exception("Field name is required with verb getUniqueVal");
			}
		}

		// Tb must have prefix
		if ($this->get['tb'] && strpos($this->get['tb'], PREFIX) === false) {
			$this->get['tb'] = PREFIX . $this->get['tb'];
		}
		// Validate table
		if (in_array( $this->get['tb'], $this->not_valid_tables)){
			throw new Exception("System tables cannot be queried");
		}

	}

	public function run()
	{

		try {

			$this->setOrDie();

			if ( file_exists("projects/{$this->get['app']}/mods/api/PreProcess.php")) {
				require_once("projects/{$this->get['app']}/mods/api/PreProcess.php");
				$preProcess = new PreProcess();

				$pp = $preProcess->run($this->get, $this->post);

				if ($pp['headers'] && is_array($pp['headers'])) {
					foreach ($pp['headers'] as $k => $v) {
						header("$k: $v");
					}
				}
				if ($pp['echo']) {
					echo $pp['echo'];
				}

				if ($pp['halt']) {
					return;
				}
			}

			if ($this->verb === 'getChart') {

				require_once __DIR__ . '/GetChart.php';
				$resp = GetChart::run(
					$this->get['id']
				);

			} else if ($this->verb === 'getUniqueVal') {
				require_once __DIR__ . '/GetUniqueVal.php';
				$resp = GetUniqueVal::run(
					$this->get['tb'], 
					$this->get['fld'], 
					$this->get['s'],
					$this->get['w']
				);
				

			} else if ($this->verb === 'getVocabulary') {
				$VocClass = new Vocabulary(new DB());
				$resp = $VocClass->getValues($this->get['voc']);
				
			} else if ($this->verb === 'read') {

				// Read one record
				$resp = ReadRecord::getFull($this->app, $this->get['tb'], $this->get['id']);

			} elseif ($this->verb === 'inspect') {

				require_once __DIR__ . '/Inspect.php';
				$resp = Inspect::run(
					$this->get['tb']
				);
			}

			return $this->array2response($resp);

		} catch (Exception $e) {
			return $this->array2response([
				'type' => 'error',
				'text' => $e->getMessage(),
				'trace' => json_encode($e->getTrace(), JSON_PRETTY_PRINT)
				]);
		}
	}


	/**
	 * Formats array data as pretty-printed JSON and returns or echoes (with Cross-domain allow policy) it
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
			$flag = $this->pretty ? JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE : JSON_UNESCAPED_UNICODE;
			$data = json_encode($data, (version_compare(PHP_VERSION, '5.4.0') >= 0 ? $flag : false));
		}

		header('Access-Control-Allow-Origin: *');
		header('Content-type: ' . $mime . '; charset=utf-8');
		echo $data;


	}

}
