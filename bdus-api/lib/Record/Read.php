<?php
/**
 *
 * @uses cfg
 * @uses DB
 */
namespace Record;

class Read
{

  /**
   * Return a complete array of record data
   * @param  string $app Application name
   * @param  string $tb  Table name
   * @param  int    $id  Record ID
   * @return array      Complete array of record data
   *
   * "metadata": {
   *    "tb_id": (referenced table full name),
   *    "tb_stripped": (referenced table name without prefix),
   *    "tb_label": (referenced table label),
   * },
   * "core":        { see self::getTbRecord for docs    },
   * "plugins":     { see self::getPlugins for docs     },
   * "links":       { see self::getLinks for docs       },
   * "backlinks":   { see self::getBackLinks for docs   },
   * "manualLinks": { see self::getManualLinks for docs },
   * "files":       { see self::getFiles for docs       },
   * "geodata":     { see self::getGeodata for docs     },
   * "rs":          { see self::getRs for docs          }
   */
  public static function getFull(string $app, string $tb, int $id)
  {
    $core = self::getCore($tb, $id) ?: [];

		return [
			'metadata' => [
				'tb_id' => $tb,
				'rec_id' => $id,
				'tb_stripped' => str_replace(PREFIX, null, $tb),
				'tb_label' => \cfg::tbEl($tb, 'label')
			],
			'core'       => $core,
			'plugins'    => self::getPlugins($app, $tb, $id),
			'links'      => self::getLinks($app, $tb, $core),
			'backlinks'  => self::getBackLinks($app, $tb, $id),
      'manualLinks'=> self::getManualLinks($app, $tb, $id),
			'files'      => self::getFiles($app, $tb, $id),
      'geodata'    => self::getGeodata($app, $tb, $id),
      'rs'         => \cfg::tbEl($tb, 'rs') ? self::getRs($app, $tb, $id): []
		];
  }

    /**
   * Returns array with core data
   * @param  string  $tb   name
   * @param  int    $id   Record ID
   * @return array        Array of table data
   *
   *    "id": {
   *        "name": (field id),
   *        "label": (field label),
   *        "val": (value),
   *        "val_label": (if available — id_from_table fields — value label)
   *    },
   *    {...}
   */
  public static function getCore($tb, $id)
  {
    return self::getTbRecord($tb, "`{$tb}`.`id` = ?", [$id], true);
  }

  /**
   * Return array of manually entered links
   * @param  string $app Application name
   * @param  string $tb  Table name
   * @param  int    $id  Record ID
   * @return array      Array of manually entered links
   * link_id: {
   *    "key": (int),
   *    "tb_id": (referenced table full name),
   *    "tb_stripped": (referenced table name without prefix),
   *    "tb_label": (referenced table Label),
   *    "ref_id": (int),
   *    "ref_label": (string|int)
   * }
   */
  public static function getManualLinks(string $app, string $tb, int $id)
  {
    $manualLinks = [];
		$res = \DB::start()->query(
      "SELECT * FROM `" . PREFIX . "userlinks` WHERE (`tb_one` = ? AND `id_one` = ?) OR (`tb_two` = ? AND `id_two` = ?) ORDER BY `sort`, `id`",
      [ $tb, $id, $tb, $id ],
      'read'
    );

		if (is_array($res) && !empty($res)) {

			foreach ($res as $r) {
				if ($tb == $r['tb_one'] AND $id == $r['id_one']) {

          $mlt = $r['tb_two'];
          $mli = $r['id_two'];

				} else if ($tb == $r['tb_two'] AND $id == $r['id_two']) {

          $mlt = $r['tb_one'];
          $mli = $r['id_one'];

				}

        if ($mlt === PREFIX . 'files' ){
          continue;
        }

        $id_fld = \cfg::tbEl($mlt, 'id_field');

        if ( $id_fld === 'id' ) {
          $ref_val_label = $mli;
        } else {
          $lres= \DB::start()->query(
            "SELECT {$id_fld} as `label` FROM {$mlt} WHERE `id` = ?",
            [$mli],
            'read'
          );
          $ref_val_label = $lres[0]['label'];
        }

        $manualLinks[$r['id']] = [
          "key"         => $r['id'],
          "tb_id"       => $mlt,
          "tb_stripped" => str_replace(PREFIX, null, $mlt),
          "tb_label"    => \cfg::tbEl($mlt, 'label'),
          "ref_id"      => $mli,
          "ref_label"   => $ref_val_label,
          "sort"   => $r['sort']
        ];

			}
		}

    return $manualLinks;
  }

  /**
   * Returns array of RS data, if available
   * @param  string $app Application name
   * @param  string $tb  Table name
   * @param  int    $id  Record ID
   * @return array      array of RS data or empty array
   * {
   *    "id": "1",
   *    "first": (int),
   *    "second": (int),
   *    "relation": (int)
   * }
   */
  public static function getRs(string $app, string $tb, int $id)
  {
    $res = \DB::start()->query(
      "SELECT `id`, `first`, `second`, `relation` FROM `" . PREFIX . "rs` WHERE `tb`= ? AND (`first`= ? OR `second` = ?)",
      [$tb, $id, $id],
      'read'
    );

    $ret = [];

    if ($res && !\is_array($res)){
      foreach ($res as $key => $value) {
        $ret[$value['id']] = $value;
      } 
    }

    return $ret;
  }

  /**
   * [getGeodata description]
   * @param  string $app Application name
   * @param  string $tb  Table name
   * @param  int    $id  Record ID
   * @return [type]      [description]
   * {
   *    "id": (int),
   *    "geometry": (string, wkt),
   *    "geo_el_elips": (int),
   *    "geo_el_asl": (int)
   *    "geojson": (string, geojson)
   * }
   */
  public static function getGeodata(string $app, string $tb, int $id)
  {
    $r = \DB::start()->query(
      "SELECT `id`, `geometry`, `geo_el_elips`, `geo_el_asl` FROM `" . PREFIX . "geodata` WHERE `table_link` = ? AND `id_link`= ?",
      [$tb, $id]);
    
    $ret = [];
    if (is_array($r)) {
      foreach ($r as $row) {        
        $row['geojson'] = \Symm\Gisconverter\Gisconverter::wktToGeojson($row['geometry']);
        $ret[$row['id']] = $row;
      }
    }
    return $ret;
  }

  /**
   * Returns list of files linked to the record
   * @param  string $app Application name
   * @param  string $tb  Table name
   * @param  int    $id  Record ID
   * @return [type]      [description]
   * {
   *    "id": (int),
   *    "creator": (int),
   *    "ext": (string),
   *    "keywords": (string)
   *    "description": (string)
   *    "printable": (boolean)
   *    "filename": (string)
   * }

   */
  public static function getFiles(string $app, string $tb, int $id)
  {
    $prefix = PREFIX;
    $sql = <<<EOD
SELECT `{$prefix}files`.*
FROM `{$prefix}files`
      INNER JOIN
      `{$prefix}userlinks` AS `ul` ON (`ul`.`tb_one` = '{$prefix}files' AND 
                                  `ul`.`id_one` = `{$prefix}files`.`id` AND 
                                  `ul`.`tb_two` = ? AND 
                                  `ul`.`id_two` = ?) OR 
                                (`ul`.`tb_two` = '{$prefix}files' AND 
                                  `ul`.`id_two` = `{$prefix}files`.`id` AND 
                                  `ul`.`tb_one` = ? AND 
                                  `ul`.`id_one` = ?) 
WHERE 1
ORDER BY `ul`.`sort`;
  
)
EOD;
		$sql_val = [
      $tb,
      $id,
      $tb,
      $id
    ];
    return DB::start()->query($sql, $sql_val);
  }

  /**
   * Returns array of backlinks data
   * @param  string $app Application name
   * @param  string $tb  Table name
   * @param  int    $id  Record ID
   * @return array      Array with backlink data
   *
   * "backlinks": {
   *    "(referenced table full name)": {
   *        "tb_id": (referenced table full name),
   *        "tb_stripped": (referenced table name without prefix),
   *        "tb_label": (referenced table Label),
   *        "tot": (total number of links found),
   *        "data": [
   *          {
   *            "id": (int),
   *            "label": (string)
   *          },
   */
  public static function getBackLinks(string $app, string $tb, int $id)
  {
    $backlinks = [];
		$bl_data = \cfg::tbEl($tb, 'backlinks');

    if (is_array($bl_data)) {

			foreach ($bl_data as $bl) {

				list($ref_tb, $via_plg, $via_plg_fld) = utils::csv_explode($bl, ':');
        $ref_tb_id = \cfg::tbEl($ref_tb, 'id_field');

        $where = " `id` IN (SELECT DISTINCT `id_link` FROM `{$via_plg}` WHERE `table_link` = '{$ref_tb}' AND `{$via_plg_fld}` = {$id})";

        // $sql = "SELECT count(`id`) as `tot` FROM `{$via_plg}` WHERE `table_link` = '{$ref_tb}' AND `{$via_plg_fld}` = ?";
        $sql = "SELECT count(`id`) as `tot` FROM `{$ref_tb}` WHERE `id` IN (SELECT DISTINCT `id_link` FROM `{$via_plg}` WHERE `table_link` = '{$ref_tb}' AND `{$via_plg_fld}` = ?)";

        $sql_val = [$id];


        $r = \DB::start()->query($sql, $sql_val);
        if ($r[0]['tot'] == 0){
          continue;
        }

        $backlinks[$ref_tb] = [
          'tb_id' => $ref_tb,
          'tb_stripped' => str_replace(PREFIX, null, $ref_tb),
					"tb_label" => \cfg::tbEl($ref_tb, 'label'),
					'tot' => $r[0]['tot'],
          'where' => $where,
					'data' => $r
        ];
			}
		}
    return $backlinks;
  }

  /**
   * Returns array with (system) links data
   * @param  string $app Application name
   * @param  string $tb  Table name
   * @param  int    $id  Record ID
   * @return array       Array of links data, or empty array
   *
   * "(referenced table full name)": {
   *    "tb_id": (referenced table full name),
   *    "tb_stripped": (referenced table name without prefix),
   *    "tb_label": (referenced table label),
   *    "tot": (total number of links found),
   *    "where": (SQL where statement to fetch records)
 *    },
   */
  public static function getLinks(string $app, string $tb, array $core)
  {
    $links = [];

		$links_data = \cfg::tbEl($tb, 'link');

		if (is_array($links_data)) {

			foreach ($links_data as $ld) {
        $where = [];
				foreach ($ld['fld'] as $c) {
					$where[] = " `{$c['other']}` = '{$core[$c['my']]['val']}'";
				}
				$sql = "SELECT count(id) as tot FROM `{$ld['other_tb']}` WHERE " . implode($where, ' AND ');

				$r = \DB::start()->query($sql);

				$links[$ld['other_tb']] = [
					'tb_id' => $ld['other_tb'],
					'tb_stripped' => str_replace(PREFIX, null, $ld['other_tb']),
					"tb_label" => \cfg::tbEl($ld['other_tb'], 'label'),
					'tot' => $r[0]['tot'],
					'where' => implode($where, ' AND ')
				];
			}
		}
    return $links;
  }

  /**
   * Returns array with plugins data
   * @param  string $app Application name
   * @param  string $tb  Table name
   * @param  int    $id  Record ID
   * @return array      Array of plugins data, or empty array
   *
   * "(referenced plugin table full name)": {
   *    "metadata": {
   *        "tb_id": (referenced plugin table full name),
   *        "tb_stripped": (referenced plugin table name without prefix),
   *        "tb_label": (referenced plugin table label),
   *        "tot": (total number of items found)
   *    },
   *    "data": [
   *        {
   *            "(field id)": {
   *                "name": (field id),
   *                "label": (field label),
   *                "val": (value),
   *                "val_label": (if available — id_from_table fields — value label)
   *            },
   *        },
   *        {...}
   *    ]
   * }
   */
  public static function getPlugins(string $app, string $tb, int $id)
  {
    $plugins = [];

		$plg_names = \cfg::tbEl($tb, 'plugin');
		if ($plg_names && is_array($plg_names)){
			foreach ($plg_names as $p) {
				$plg_data = self::getTbRecord($p, "`table_link`= ? AND `id_link` = ?", [$tb, $id], false, true) ?: [];
        if (empty($plg_data)){
          continue;
        }
        $indexed_plg_data = [];
        foreach ($plg_data as $key => $row) {
          $indexed_plg_data[$row['id']['val']];
        }
        // sort records using sort field, if available
  			if (in_array('sort', array_keys(reset($plg_data)))) {
  				usort($plg_data, function($a, $b){
  					if ($a['sort'] === $b['sort']) {
  							return 0;
  					}
  					return ($a['sort'] > $b['sort']) ? 1 : -1;
  				});
  			}

				$plugins[$p] = [
					"metadata" => [
						"tb_id" => $p,
						"tb_stripped" => str_replace(PREFIX, null, $p),
						"tb_label" => \cfg::tbEl($p, 'label'),
						"tot" => count($plg_data)
					],
					"data" => $plg_data
				];
			}
		}
    return $plugins;
  }

  /**
   * [getTbRecord description]
   * @param  string  $tb           Table name
   * @param  string  $sql          Where SQl statement
   * @param  array   $sql_val      binding data
   * @param  boolean $return_first If true only the first row of the results will be returned
   * @param  boolean $return_all_fields If true All fields, in case of JOIN, will be returned, otherwize table namespace will be used
   * @return array                array of table data
   *
   * "core": {
   *    "id": {
   *        "name": (field id),
   *        "label": (field label),
   *        "val": (value),
   *        "val_label": (if available — id_from_table fields — value label)
   *    },
   *    {...}
   */
  private static function getTbRecord(string $tb, string $sql, array $sql_val = [], bool $return_first = false, bool $return_all_fields = false)
	{
		$cfg = \cfg::fldEl($tb, 'all', 'all');

		$fields = $return_all_fields ? ["*"] : ["{$tb}.*"];
		$join = [];

		foreach ($cfg as $arr) {

      if ($arr['id_from_tb']) {

				$ref_tb = $arr['id_from_tb'];
				$ref_alias = uniqid('al');
				$ref_tb_fld = \cfg::tbEl($arr['id_from_tb'], 'id_field');

        if ($tb === $ref_tb) {
          continue;
        }

				array_push(
					$fields,
					"`{$ref_alias}`.`{$ref_tb_fld}` AS `@{$arr['name']}`");

				array_push(
					$join,
					" LEFT JOIN `{$ref_tb}` AS `{$ref_alias}` ON `{$ref_alias}`.`id` = `{$tb}`.`{$arr['name']}` ");
			}
		}

		$full_sql = 'SELECT ' . implode(', ', $fields) .
			" FROM `{$tb}` " .
		 	implode(' ', $join) .
		 	" WHERE {$sql}";

		$r = \DB::start()->query($full_sql, $sql_val, 'read');

		if (!$r){
			return false;
		}

		$ret = [];
		$return_arr = [];

		foreach ($r as $res) {
			foreach ($res as $k => $v) {
				if (strpos($k, '@') === false){
					$ret[$k] = [
						'name' => $k,
						'label' => \cfg::fldEl($tb, $k, 'label'),
						'val' => $v
					];
				} else {
					$ret[str_replace('@', '', $k)]['val_label'] = $v;
				}
			}
			$return_arr[(int)$res['id']] = $ret;
		}

		return $return_first ? reset($return_arr) : $return_arr;
	}
}

?>
