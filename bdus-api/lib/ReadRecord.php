<?php
/**
 *
 * @uses cfg
 * @uses DB
 */
class ReadRecord
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
    $core = self::getTbRecord($tb, "`{$tb}`.`id` = ?", [$id], true) ?: [];

		return [
			'metadata' => [
				'tb_id' => $tb,
				'tb_stripped' => str_replace($app . '__', null, $tb),
				'tb_label' => cfg::tbEl($tb, 'label')
			],
			'core'       => $core,
			'plugins'    => self::getPlugins($app, $tb, $id),
			'links'      => self::getLinks($app, $tb, $core),
			'backlinks'  => self::getBackLinks($app, $tb, $id),
      'manualLinks'=> self::getManualLinks($app, $tb, $id),
			'files'      => self::getFiles($app, $tb, $id),
      'geodata'    => self::getGeodata($app, $tb, $id),
      'rs'         => self::getRs($app, $tb, $id)
		];
  }

  /**
   * Return array of manually entered links
   * @param  string $app Application name
   * @param  string $tb  Table name
   * @param  int    $id  Record ID
   * @return array      Array of manually entered links
   * {
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
		$res = DB::start()->query(
      "SELECT * FROM `{$app}__userlinks` WHERE (`tb_one` = ? AND `id_one` = ?) OR (`tb_two` = ? AND `id_two` = ?) ORDER BY `sort`, `id`",
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

        if ($mlt === $app . '__files' ){
          continue;
        }

        $id_fld = cfg::tbEl($mlt, 'id_field');

        if ( $id_fld === 'id' ) {
          $ref_val_label = $mli;
        } else {
          $lres= DB::start()->query(
            "SELECT {$id_fld} as `label` FROM {$mlt} WHERE `id` = ?",
            [$mli],
            'read'
          );
          $ref_val_label = $lres[0]['label'];
        }

        array_push($manualLinks, [
          "key"         => $r['id'],
          "tb_id"       => $mlt,
          "tb_stripped" => str_replace($app . '__', null, $mlt),
          "tb_label"    => cfg::tbEl($mlt, 'label'),
          "ref_id"      => $mli,
          "ref_label"   => $ref_val_label
        ]);

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
    return DB::start()->query(
      "SELECT `id`, `first`, `second`, `relation` FROM `{$app}__rs` WHERE `tb`= ? AND (`first`= ? OR `second` = ?)",
      [$tb, $id, $id],
      'read'
    );
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
    $r = DB::start()->query(
      "SELECT `id`, `geometry`, `geo_el_elips`, `geo_el_asl` FROM `{$app}__geodata` WHERE `table_link` = ? AND `id_link`= ?",
			[$tb, $id]);
    if (is_array($r)) {
      foreach ($r as &$row) {
        $row['geojson'] = \Symm\Gisconverter\Gisconverter::wktToGeojson($row['geometry']);
      }
    }
    return $r;
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
    $sql = <<<EOD
SELECT * FROM `{$app}__files` WHERE `id` IN (
  SELECT `id_one` FROM `{$app}__userlinks` WHERE `tb_one` = :files AND `tb_two` = :tb AND `id_two` = :id
  UNION
  SELECT `id_two` FROM `{$app}__userlinks` WHERE `tb_two` = :files AND `tb_one` = :tb AND `id_one` = :id
)
EOD;
		$sql_val = [
      'files' => "{$app}__files",
      'tb' => $tb,
      'id' => $id
    ];
		$files =  DB::start()->query($sql, $sql_val);

    usort($files, function($a, $b){
      if ($a['sort'] === $b['sort']) {
          return 0;
      }
      return ($a['sort'] > $b['sort']) ? 1 : -1;
    });
    return $files;
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
		$bl_data = cfg::tbEl($tb, 'backlinks');

    if (is_array($bl_data)) {

			foreach ($bl_data as $bl) {

				list($ref_tb, $via_plg, $via_plg_fld) = utils::csv_explode($bl, ':');
        $ref_tb_id = cfg::tbEl($ref_tb, 'id_field');

        $where = " `id` IN (SELECT DISTINCT `id_link` FROM `{$via_plg}` WHERE `table_link` = '{$ref_tb}' AND `{$via_plg_fld}` = {$id})";

        // $sql = "SELECT count(`id`) as `tot` FROM `{$via_plg}` WHERE `table_link` = '{$ref_tb}' AND `{$via_plg_fld}` = ?";
        $sql = "SELECT count(`id`) as `tot` FROM `{$ref_tb}` WHERE `id` IN (SELECT DISTINCT `id_link` FROM `{$via_plg}` WHERE `table_link` = '{$ref_tb}' AND `{$via_plg_fld}` = ?)";

        $sql_val = [$id];


        $r = DB::start()->query($sql, $sql_val);
        if ($r[0]['tot'] == 0){
          continue;
        }

        $backlinks[$ref_tb] = [
          'tb_id' => $ref_tb,
          'tb_stripped' => str_replace($app . '__', null, $ref_tb),
					"tb_label" => cfg::tbEl($ref_tb, 'label'),
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

		$links_data = cfg::tbEl($tb, 'link');

		if (is_array($links_data)) {

			foreach ($links_data as $ld) {
        $where = [];
				foreach ($ld['fld'] as $c) {
					$where[] = " `{$c['other']}` = '{$core[$c['my']]['val']}'";
				}
				$sql = "SELECT count(id) as tot FROM `{$ld['other_tb']}` WHERE " . implode($where, ' AND ');

				$r = DB::start()->query($sql);

				$links[$ld['other_tb']] = [
					'tb_id' => $ld['other_tb'],
					'tb_stripped' => str_replace($app . '__', null, $ld['other_tb']),
					"tb_label" => cfg::tbEl($ld['other_tb'], 'label'),
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

		$plg_names = cfg::tbEl($tb, 'plugin');
		if ($plg_names && is_array($plg_names)){
			foreach ($plg_names as $p) {
				$plg_data = self::getTbRecord($p, "`table_link`= ? AND `id_link` = ?", [$tb, $id]) ?: [];
        if (empty($plg_data)){
          continue;
        }
        // sort records using sort field, if available
  			if (in_array('sort', array_keys($plg_data[0]))) {
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
						"tb_stripped" => str_replace($app . '__', null, $p),
						"tb_label" => cfg::tbEl($p, 'label'),
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
  private static function getTbRecord(string $tb, string $sql, array $sql_val = [], bool $return_first = false)
	{
		$cfg = cfg::fldEl($tb, 'all', 'all');

		$fields = ['*'];
		$join = [];

		foreach ($cfg as $arr) {

      if ($arr['id_from_tb']) {

				$ref_tb = $arr['id_from_tb'];
				$ref_tb_fld = cfg::tbEl($arr['id_from_tb'], 'id_field');

        if ($tb === $ref_tb) {
          continue;
        }

				array_push(
					$fields,
					"`{$ref_tb}`.`{$ref_tb_fld}` AS `@{$arr['name']}`");

				array_push(
					$join,
					" JOIN `{$ref_tb}` ON `{$ref_tb}`.`id` = `{$tb}`.`{$arr['name']}` ");
			}
		}

		$full_sql = 'SELECT ' . implode(', ', $fields) .
			" FROM `{$tb}` " .
		 	implode(' ', $join) .
		 	" WHERE {$sql}";

		$r = DB::start()->query($full_sql, $sql_val, 'read');

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
						'label' => cfg::fldEl($tb, $k, 'label'),
						'val' => $v
					];
				} else {
					$ret[str_replace('@', '', $k)]['val_label'] = $v;
				}
			}
			array_push($return_arr, $ret);
		}

		return $return_first ? $return_arr[0] : $return_arr;
	}
}

?>
