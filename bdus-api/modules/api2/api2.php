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
		$valid_verbs = ['read', 'search', 'inspect', 'getChart', 'getUniqueVal'];

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
		if (in_array( $this->get['tb'], [
			PREFIX . "queries",
			PREFIX . "rs",
			PREFIX . "userlinks",
			PREFIX . "users",
			PREFIX . "charts"
			])){
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

				if ($this->get['id'] && $this->get['id'] !== 'all'){
					$sql = "SELECT * FROM `" . PREFIX . "charts` WHERE `id` = ?";
					$vals = [ $this->get['id'] ];
					$ch = DB::start()->query($sql, $vals);

					if (!$ch || !is_array($ch) || !is_array($ch[0])){
						throw new \Exception("Chart #{$this->get['id']} not found");
					}

					$resp['name'] = $ch[0]['name'];
					$resp['id'] = $ch[0]['id'];

					$resp['data'] = DB::start()->query($ch[0]['query']);
				} elseif ($this->get['id'] === 'all') {
					$sql = "SELECT `id`, `name` FROM `" . PREFIX . "charts` WHERE  1";
					$resp = DB::start()->query($sql, $vals);
				}


			} else if ($this->verb === 'getUniqueVal') {

				$fld_type = cfg::fldEl($this->get['tb'], $this->get['fld'], 'type');
				$id_from_tb = cfg::fldEl($this->get['tb'], $this->get['fld'], 'id_from_tb');
				if ($id_from_tb){
					$f = cfg::tbEl($id_from_tb, 'id_field');
					$sql = "SELECT DISTINCT `{$f}` as `f` FROM `{$id_from_tb}` WHERE ";
				} else {
					$sql = "SELECT DISTINCT `{$this->get['fld']}` as `f` FROM `{$this->get['tb']}` WHERE ";
					$f = $this->get['fld'];
				}
				if ($this->get['s']){
					$sql .= " {$f} LIKE ? LIMIT 0, 30";
					$res = DB::start()->query($sql, ["%{$this->get['s']}%"]);
				} else {
					$sql .= ' 1';
					$res = DB::start()->query($sql);
				}

				$resp = [];
				foreach ($res as $v) {
					if ($v['f'] === null || trim($v['f']) === ''){
						continue;
					}
					if ( $fld_type === 'multi_select' && strpos($v['f'], ';')){
						$v_a = utils::csv_explode($v['f'], ';');
						foreach ($v_a as $i) {
							if (!in_array($i, $resp)){
								array_push($resp, $i);
							}
						}
					} else {
						array_push($resp, $v['f']);
					}
				}



			} else if ($this->verb === 'read') {

				// Read one record
				$resp = $this->getOne($this->app, $this->get['tb'], $this->get['id']);

			} elseif ($this->verb === 'inspect') {

				// Inspect
				if ($this->get['tb']) {

					// Inspect table
					$stripped_name = str_replace(PREFIX, null, $this->get['tb']);

					$resp = cfg::tbEl($this->get['tb'], 'all');
					$resp['stripped_name'] = $stripped_name;

					foreach (cfg::fldEl($this->get['tb']) as $f){
						$f['fullname'] = $this->get['tb'] . ':' . $f['name'];
						$resp['fields'][$f['fullname']] = $f;
					}

					// Plugins
					foreach (cfg::tbEl($this->get['tb'], 'plugin') as $p) {
						foreach (cfg::fldEl($p) as $f){
							$f['fullname'] = $p . ':' . $f['name'];
							$f['label'] = cfg::tbEl($p, 'label') . ': ' . $f['label'];
							$resp['fields'][$f['fullname']] = $f;
						}
					}

				} else {

					// Inspect all
					foreach (cfg::tbEl('all', 'all') as $t) {

						$stripped_name = str_replace(PREFIX, null, $t['name']);
						$t['stripped_name'] = $stripped_name;

						foreach (cfg::fldEl($t['name']) as $f){
							$f['fullname'] = $t['name'] . ':' . $t['name'];
							$t['fields'][$f['name']] = $f;
						}


						$resp[$stripped_name] = $t;
					}
				}
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
