<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			See file LICENSE distributed with this code
 * @since			Aug 22, 2012
 */

class geoface_ctrl
{
	/**
	 * Saves new geometry data to geodata table, linked to table, id
	 * @param string $tb
	 * @param int $id
	 * @param string $geometry WKT format
	 * @throws myException
	 */
	public static function saveNew($tb, $id, $geometry)
	{
		try
		{
			if (!utils::canUser('add_new'))
			{
				throw new myException('User has not enough privilege to add a new record');
			}
			
			$record = new Record($tb, $id, new DB());
			
			
			if ($record->addGeodata($geometry))
			{
				utils::response('ok_insert_geodata');
			}
			else
			{
				throw new myException('Insert geodata query returned false');
			}
		}
		catch (myException $e)
		{
			$e->log();
			utils::response('error_insert_geodata', 'error');
		}
		
	}
	
	
	/**
	 * Erases geoemtry from geodatada table
	 * @param int $id
	 * @throws myException
	 */
	public static function erase($id)
	{
		try
		{
			if (!utils::canUser('edit'))
			{
				throw new myException('User has not enough privilege to edit records');
			}
			
			$record = new Record('novalue', false, new DB());
			
			if ($record->deleteGeodata($id))
			{
				utils::response('ok_delete_geodata');
			}
			else
			{
				throw new myException('Delete geodata query returned false');
			}
		}
		catch (myException $e)
		{
			$e->log();
			utils::response('error_delete_geodata', 'error');
		}
	}
	
	/**
	 * Updates geometri info in geodata table
	 * @param int $id
	 * @param string $geometry	WKT format
	 * @throws myException
	 */
	public static function update($id, $geometry)
	{
		try
		{
			if (!utils::canUser('edit'))
			{
				throw new myException('User has not enough privilege to edit records');
			}
			
			if (DB::start()->query('UPDATE `' . PREFIX . '__geodata` SET `geometry` = :geometry WHERE `id` = ' . $id, array(':geometry'=>$geometry), 'boolean'))
			{
				utils::response('ok_update_geometry');
			}
			else
			{
				throw new myException('Update geometry query returned false');
			}
		}
		catch (myException $e)
		{
			$e->log();
			utils::response('error_update_geometry', 'error');
		}
	}
	
	/**
	 * Returns complex object with metadata and geodata to use for mapping
	 * @param string $tb
	 * @param string $where	base64_encoded where statement
	 */
	public static function getGeoJson($tb, $where = false)
	{
		try
		{
			$geoface = cfg::tbEl($tb, 'geoface');
			
			if (!$geoface['epsg'])
			{
				$geoface['epsg'] = '4326';
			}
			if (!is_array($geoface['layers']['web']))
			{
				$geoface['layers']['web'] = array();
			}
	
			if (is_array($geoface['layers']['local']))
			{
				foreach ($geoface['layers']['local'] as &$vec)
				{
					$vec['id'] = PROJ_GEO_DIR. $vec['id'];
				}
			}
	
			$preview = cfg::getPreviewFlds($tb);
			
			$part = array();
	
			foreach ($preview as $fldid)
			{
				if ($fldid != 'id')
				{
					$part[] = '`' . $tb . '`.`' . $fldid . '` AS `' . cfg::fldEl($tb, $fldid, 'label') . '`';
				}
			}

			$where ? $where = base64_decode($where) : '';
			
			if (!preg_match('/`' . $tb . '`\.`id`/', $where) && $where)
			{
				$where = str_replace('`id`', '`' . $tb . '`.`id`', $where);
			}
			$sql = 'SELECT `' . $tb . '`.`id`  AS `id`, ' . implode(', ', $part) . ', `' . PREFIX . '__geodata`.`id` AS `geo_id`,  `geometry` '
			. ' FROM `' . $tb . '` LEFT JOIN `' . PREFIX . '__geodata` '
			. " ON `" . $tb . "`.`id` = `" . PREFIX . "__geodata`.`id_link` AND `" . PREFIX . "__geodata`.`table_link` = '" . $tb . "' "
			. ' WHERE `geometry` IS NOT NULL '
			. ($where ? ' AND ' . $where : '');
				
			$db = new DB();
			
			$res = $db->query($sql, false);
			
			if($res)
			{
				$geo = new sql2geoJSON();
					
				foreach($res as $row)
				{
					$geo->addFeature($row);
				}
				$response['status'] = 'success';
				$response['data'] = $geo->getObject();
				
			}
			else if (!$res AND (trim($where) == '1' || !$where) && utils::canUser('add_new'))
			{
				$response['status'] = 'warning';
				$response['data'] = '';
			}
			else
			{
				utils::response('no_geodata_available', 'error');
				return;
			}
			
			$response['metadata'] = array_merge($geoface, array(
					'tb_id'=>$tb,
					'tb'=>cfg::tbEl($tb, 'label'),
					'canUserEdit' => utils::canUser('edit')
			));
			
			echo json_encode($response);
		}
		catch (myException $e)
		{
			$e->log();
			utils::response('error_getting_geodata', 'error');
		}
	}
	
	
}