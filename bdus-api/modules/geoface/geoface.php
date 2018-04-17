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
	 * @throws myException
	 */
	public function saveNew()
	{
		$tb = $this->request['tb'];
		$id = $this->request['id'][0];
		$geometry = $this->request['coords'];

		try {

			if (!utils::canUser('add_new')) {
				throw new myException('User has not enough privilege to add a new record');
			}

			$record = new Record($tb, $id, new DB());

			$new_id = $record->addGeodata($geometry);

			if ($new_id) {
				utils::response('ok_insert_geodata', false, false, array('id'=>$new_id));
			} else {
				throw new myException('Insert geodata query returned false');
			}

		} catch (myException $e) {
			$e->log();
			utils::response('error_insert_geodata', 'error');
		}

	}


	/**
	 * Erases geoemtry from geodatada table
	 * @throws myException
	 */
	public function erase()
	{
		$id_arr = $this->post['ids'];

		try {

			if (!utils::canUser('edit')) {
				throw new myException('User has not enough privilege to edit records');
			}

			$record = new Record('novalue', false, new DB());

			foreach ($id_arr as $id) {
				if (!$record->deleteGeodata($id)) {
					$error = true;
				}
			}

			if (!$error) {
				utils::response('ok_delete_geodata');
			} else {
				throw new myException('Delete geodata query returned false');
			}

		} catch (myException $e) {
			$e->log();
			utils::response('error_delete_geodata', 'error');
		}
	}

	/**
	 * Updates geometri info in geodata table
	 * @throws myException
	 */
	public function update()
	{
		$post = $this->post['geodata'];

		try {
			if (!utils::canUser('edit')) {
				throw new myException('User has not enough privilege to edit records');
			}

			$db = new DB();

			foreach ($post as $row){
				$ret = $db->query('UPDATE `' . PREFIX . '__geodata` SET ' .
						  '`geometry` = :geometry ' .
						  'WHERE `id` = ' . $row['id'],
						  array(':geometry'=>$row['coords']), 'boolean');
				if (!$ret) {
					$error = true;
				}
			}

			if (!$error) {
				utils::response('ok_update_geometry');
			} else {
				throw new myException('Update geometry query returned false');
			}

		} catch (myException $e) {
			$e->log();
			utils::response('error_update_geometry', 'error');
		}
	}


	/**
	 * Returns complex object with metadata and geodata to use for mapping
	 * @return string
	 */
	public function getGeoJson()
	{
		$tb = $this->request['tb'];
		$where = $this->request['where'];

		try {

			$geoface = cfg::tbEl($tb, 'geoface');

			if (!is_array($geoface['layers']['web'])) {
				$geoface['layers']['web'] = array();
			}

			if (is_array($geoface['layers']['local'])) {
				foreach ($geoface['layers']['local'] as &$vec) {
					$vec['id'] = PROJ_GEO_DIR. $vec['id'];
				}
			}

			$preview = cfg::getPreviewFlds($tb);

			$part = [];

			foreach ($preview as $fldid) {
				if ($fldid != 'id') {
					array_push($part, '`' . $tb . '`.`' . $fldid . '` AS `' . cfg::fldEl($tb, $fldid, 'label') . '`');
				}
			}

			$where ? $where = base64_decode($where) : '';

			if (!preg_match('/`' . $tb . '`\.`id`/', $where) && $where) {
				$where = str_replace('`id`', '`' . $tb . '`.`id`', $where);
			}

			$sql = 'SELECT `' . $tb . '`.`id`  AS `id`, ' . implode(', ', $part) . ', `' . PREFIX . '__geodata`.`id` AS `geo_id`,  `geometry` '
			. ' FROM `' . $tb . '` LEFT JOIN `' . PREFIX . '__geodata` '
			. " ON `" . $tb . "`.`id` = `" . PREFIX . "__geodata`.`id_link` AND `" . PREFIX . "__geodata`.`table_link` = '" . $tb . "' "
			. ' WHERE `geometry` IS NOT NULL '
			. ($where ? ' AND ' . $where : '');

			$db = new DB();

			$res = $db->query($sql, false);

			if($res) {

				$response['status'] = 'success';
				$response['data'] = toGeoJson::fromMultiArray($res, true);

			} else if (!$res AND (trim($where) == '1' || !$where) && utils::canUser('add_new')) {

				$response['status'] = 'warning';
				$response['data'] = '';

			} else {
				utils::response('no_geodata_available', 'error');
				return;
			}

			$response['metadata'] = array_merge($geoface, [
				'tb_id'=>$tb,
				'tb'=>cfg::tbEl($tb, 'label'),
				'gmapskey' => cfg::main('gmapskey'),
				'canUserEdit' => utils::canUser('edit')
			]);

			echo json_encode($response);

		} catch (myException $e) {
			$e->log();
			utils::response('error_getting_geodata', 'error');
		}
	}


}
