<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 22, 2012
 */

class geoface_ctrl extends Controller
{
	/**
	 * Saves new geometry data to geodata table, linked to table, id
	 * @throws \Exception
	 */
	public function saveNew()
	{
		$tb = $this->request['tb'];
		$id = $this->request['id'][0];
		$geometry = $this->request['coords'];

		try {

			if (!\utils::canUser('add_new')) {
				throw new \Exception('User has not enough privilege to add a new record');
			}

			$record = new Record($tb, $id, $this->db, $this->cfg);

			$new_id = $record->addGeodata($geometry);

			if ($new_id) {
				$this->response('ok_insert_geodata', 'success', null, [ 'id' => $new_id ] );
			} else {
				throw new \Exception('Insert geodata query returned false');
			}

		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->response('error_insert_geodata', 'error');
		}

	}


	/**
	 * Erases geoemtry from geodatada table
	 * @throws \Exception
	 */
	public function erase()
	{
		$id_arr = $this->post['ids'];

		try {

			if (!\utils::canUser('edit')) {
				throw new \Exception('User has not enough privilege to edit records');
			}

			$record = new Record('novalue', false, $this->db, $this->cfg);

			foreach ($id_arr as $id) {
				if (!$record->deleteGeodata($id)) {
					$error = true;
				}
			}

			if (!$error) {
				$this->response('ok_delete_geodata', 'success');
			} else {
				throw new \Exception('Delete geodata query returned false');
			}

		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->response('error_delete_geodata', 'error');
		}
	}

	/**
	 * Updates geometri info in geodata table
	 * @throws \Exception
	 */
	public function update()
	{
		$post = $this->post['geodata'];

		try {
			if (!\utils::canUser('edit')) {
				throw new \Exception('User has not enough privilege to edit records');
			}

			foreach ($post as $row){
				$ret = $this->db->query('UPDATE ' . $this->prefix . 'geodata SET geometry = ? WHERE id = ?' ,
						  [ $row['coords'], $row['id'] ],
						  'boolean');
				if (!$ret) {
					$error = true;
				}
			}

			if (!$error) {
				$this->response('ok_update_geometry', 'success');
			} else {
				throw new \Exception('Update geometry query returned false');
			}

		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->response('error_update_geometry', 'error');
		}
	}


	/**
	 * Returns complex object with metadata and geodata to use for mapping
	 * @return string
	 */
	public function getGeoJson()
	{
		$tb = $this->request['tb'];
		$obj_encoded = $this->request['obj_encoded'];

		try {

			list($where, $values) = $obj_encoded ? \SQL\SafeQuery::decode($obj_encoded) : ['1=1', []];

			$pref_preview_flds = \pref::get('preview');
			$preview = $pref_preview_flds[$tb] ?? $this->cfg->get("tables.$tb.preview");

			$part = [];

			foreach ($preview as $fldid) {
				if ($fldid != 'id') {
					array_push($part, $tb . '.' . $fldid . ' AS "' . $this->cfg->get("tables.$tb.fields.$fldid.label") . '"');
				}
			}

			if (!preg_match('/' . $tb . '\.id/', $where) && $where) {
				$where = str_replace('id', $tb . '.id', $where);
			}

			$sql = "SELECT {$tb}.id  AS id, " . implode(', ', $part) . ', ' . $this->prefix . 'geodata.id AS geo_id,  geometry '
			. " FROM  $tb LEFT JOIN " . $this->prefix . 'geodata '
			. " ON {$tb}.id = " . $this->prefix . 'geodata.id_link AND ' . $this->prefix . "geodata.table_link = '{$tb}' "
			. ' WHERE geometry IS NOT NULL '
			. ($where ? ' AND ' . $where : '');

			$res = $this->db->query($sql, $values);

			if($res) {

				$response['status'] = 'success';
				$response['data'] = \utils::mutliArray2GeoJSON($tb, $res);

			} else if (!$res AND (trim($where) == '1' || !$where) && \utils::canUser('add_new')) {

				$response['status'] = 'warning';
				$response['data'] = '';

			} else {
				$this->response('no_geodata_available', 'error');
				return;
			}

			$local_geodata = \utils::dirContent(PROJ_DIR . 'geodata');
			$local_layers = [];
			if ($local_geodata){
                foreach ($local_geodata as $vec) {
					$parts = pathinfo($vec);
					if ( in_array( strtolower($parts['extension']), [ 'csv', 'gpx', 'kml', 'wkt', 'topojson', 'geojson']) ){
						$local_layers[] = [
							'full_path' => PROJ_DIR . 'geodata' . DIRECTORY_SEPARATOR . $vec,
							'name' => $parts['filename'],
							'ext' => $parts['extension']
						];
					}
                }
			}

			

			$response['metadata'] = [
				'tb_id'			=>	$tb,
				'tb'			=>	$this->cfg->get("tables.$tb.label"),
				'gmapskey'		=>	$this->cfg->get("main.gmapskey"),
				'canUserEdit' 	=> \utils::canUser('edit'),
				'local_layers'	=> $local_layers
			];

			echo $this->returnJson($response);

		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->response('error_getting_geodata', 'error');
		}
	}


}
