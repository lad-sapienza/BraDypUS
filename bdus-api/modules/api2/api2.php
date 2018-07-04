<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since				Jul 02, 2018
 *
 * pretty
 * verb
 */

class api2 extends Controller
{
	private $app;
	private $verb;
	private $pretty = false;

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
		$valid_verbs = ['read', 'search', 'inspect'];
		if (!$this->verb || !in_array($this->verb, $valid_verbs)) {
			throw new Exception("Invalid verb {$this->verb}. Verb must be one of " . implode(', ', $valid_verbs));
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

		// Tb must have prefix
		if (strpos($this->get['tb'], $this_>app . '__') === false) {
			$this->get['tb'] = $this->app . '__' . $this->get['tb'];
		}
	}

	public function run()
	{

		try {

			$this->setOrDie();

			if ($this->verb === 'read') {
				$resp = $this->getOne($this->app, $this->get['tb'], $this->get['id']);
			}

			return $this->array2response($resp);

		} catch (Exception $e) {
			return $this->array2response([
				'type' => 'error',
				'text' => $e->getMessage()
				]);
		}
	}

	/**
	 * Return complete array of data for single record
	 * @param  string  $app        Application name
	 * @param  string  $tb         Table name
	 * @param  int     $id         Record id
	 * @param  boolean $first_only if true only the first record will be returned
	 * @return array              Array of data
	 */
	private function getOne(string $app, string $tb, int $id, bool $first_only = false)
	{
		return ReadRecord::getFull($app, $tb, $id);
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
