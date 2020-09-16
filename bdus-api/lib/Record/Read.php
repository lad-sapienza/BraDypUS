<?php
namespace Record;

use DB\DBInterface;
use Config\Config;

class Read
{
    protected $db;
    protected $cfg;
    protected $tb;
    protected $id;

    private $cache = [];

    /**
     * Initializes class
     * Sets $app and $db
     *
     * @param DBInterface $db       DB object
     */
    public function __construct(DBInterface $db, Config $cfg, string $tb, int $id)
    {
        $this->db = $db;
        $this->cfg = $cfg;
        $this->tb = $tb;
        $this->id = $id;
    }

    public function getTb() : string
    {
        return $this->tb;
    }

    /**
     * Return a complete array of record data
     * @return array      Complete array of record data
     *
     * "metadata": {
     *    "tb_id": (referenced table full name),
     *    "tb_stripped": (referenced table name without prefix),
     *    "tb_label": (referenced table label),
     * },
     * "core":        { see $this->getTbRecord for docs    },
     * "plugins":     { see $this->getPlugins for docs     },
     * "links":       { see $this->getLinks for docs       },
     * "backlinks":   { see $this->getBackLinks for docs   },
     * "manualLinks": { see $this->getManualLinks for docs },
     * "files":       { see $this->getFiles for docs       },
     * "geodata":     { see $this->getGeodata for docs     },
     * "rs":          { see $this->getRs for docs          }
     */
    public function getFull(): array
    {
        $core = $this->getCore() ?: [];

        return [
            'metadata' => [
                'tb_id' => $this->tb,
                'rec_id' => $this->id,
                'tb_stripped' => str_replace(PREFIX, null, $this->tb),
                'tb_label' => $this->cfg->get("tables.{$this->tb}.label")
            ],
            'core'       => $core,
            'plugins'    => $this->getPlugins(),
            'links'      => $this->getLinks(),
            'backlinks'  => $this->getBackLinks(),
            'manualLinks'=> $this->getManualLinks(),
            'files'      => $this->getFiles(),
            'geodata'    => $this->getGeodata(),
            'rs'         => $this->cfg->get("tables.{$this->tb}.rs") ? $this->getRs(): []
        ];
    }

    /**
   * Returns array with core data
   * @param string $fld   Firld name, to return only a segment;
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
    public function getCore(string $fld = null, bool $return_val = false)
    {
        if (!isset($this->cache['core'])) {
            $this->cache['core'] = $this->getTbRecord($this->tb, "{$this->tb}.id = ?", [$this->id], true);
        }
        if (!$fld) {
            return $this->cache['core'];
        } elseif ($return_val) {
            $this->cache['core'][$fld]['val'];
        } else {
            return $this->cache['core'][$fld];
        }
    }

    /**
     * Return array of manually entered links
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
    public function getManualLinks(): array
    {
        if (!isset($this->cache['manuallinks'])) {
            $manualLinks = [];
            $prefix = PREFIX;
            $sql = <<<EOD
SELECT {$prefix}userlinks.*
  FROM {$prefix}userlinks
 WHERE (tb_one = ? AND 
        id_one = ? AND
        tb_two != '{$prefix}files') OR 
       (tb_two = ? AND 
        id_two = ? AND
        tb_one != '{$prefix}files')
 ORDER BY sort,
          id;
)
EOD;

            $values = [
                $this->tb,
                $this->id,
                $this->tb,
                $this->id
            ];

            $res = $this->db->query($sql, $values, 'read');

            if (is_array($res) && !empty($res)) {
                foreach ($res as $r) {
                    if ($this->tb === $r['tb_one'] and $this->id === $r['id_one']) {
                        $mlt = $r['tb_two'];
                        $mli = $r['id_two'];
                    } elseif ($this->tb === $r['tb_two'] and $this->id === $r['id_two']) {
                        $mlt = $r['tb_one'];
                        $mli = $r['id_one'];
                    }

                    $id_fld = $this->cfg->get("tables.$mlt.id_field");

                    if ($id_fld === 'id') {
                        $ref_val_label = $mli;
                    } else {
                        $lres= $this->db->query(
                            "SELECT {$id_fld} as label FROM {$mlt} WHERE id = ?",
                            [$mli],
                            'read'
                        );
                        $ref_val_label = $lres[0]['label'];
                    }

                    $manualLinks[$r['id']] = [
                        "key"         => $r['id'],
                        "tb_id"       => $mlt,
                        "tb_stripped" => str_replace(PREFIX, null, $mlt),
                        "tb_label"    => $this->cfg->get("tables.$mlt.label"),
                        "ref_id"      => $mli,
                        "ref_label"   => $ref_val_label,
                        "sort"   => $r['sort']
                    ];
                }
            }
            $this->cache['manuallinks'] = $manualLinks;
        }
        
        return $this->cache['manuallinks'];
    }

    /**
     * Returns array of RS data, if available
     * @return array      array of RS data or empty array
     * {
     *    "id": "1",
     *    "first": (int),
     *    "second": (int),
     *    "relation": (int)
     * }
     */
    public function getRs()
    {
        if (!isset($this->cache['rs'])){
            $res = $this->db->query(
                "SELECT id, first, second, relation FROM " . PREFIX . "rs WHERE tb = ? AND (first= ? OR second = ?)",
                [$this->tb, $this->id, $this->id],
                'read'
            );
    
            $ret = [];
    
            if ($res && !\is_array($res)) {
                foreach ($res as $key => $value) {
                    $ret[$value['id']] = $value;
                }
            }

            $this->cache['rs'] = $ret;
        }
        return $this->cache['rs'];
    }

  /**
   * [getGeodata description]
   * @param  string $tb  Table name
   * @param  int    $id  Record ID
   * @return [type]      [description]
   * {
   *    "id": (int),
   *    "geometry": (string, wkt),
   *    "geojson": (string, geojson)
   * }
   */
  public function getGeodata(string $tb, int $id)
  {
      $r = $this->db->query(
          "SELECT id, geometry FROM " . PREFIX . "geodata WHERE table_link = ? AND id_link = ?",
          [$tb, $id]
      );
    
      $ret = [];
      if (is_array($r)) {
          foreach ($r as $row) {
              $row['geojson'] = \Symm\Gisconverter\Gisconverter::wktToGeojson($row['geometry']);
              $ret[$row['id']] = $row;
          }
      }
  }

    /**
     * Returns list of files linked to the record
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
    public function getFiles()
    {
        if (!isset($this->cache['files'])) {
            $prefix = PREFIX;
            $sql = <<<EOD
SELECT {$prefix}files.*
FROM {$prefix}files
      INNER JOIN
      {$prefix}userlinks AS ul ON (ul.tb_one = '{$prefix}files' AND 
                                   ul.id_one = {$prefix}files.id AND 
                                   ul.tb_two = ? AND 
                                   ul.id_two = ?) OR 
                                  (ul.tb_two = '{$prefix}files' AND 
                                   ul.id_two = {$prefix}files.id AND 
                                   ul.tb_one = ? AND 
                                   ul.id_one = ?) 
WHERE 1=1
ORDER BY ul.sort;
  
)
EOD;
            $sql_val = [
                $this->tb,
                $this->id,
                $this->tb,
                $this->id
            ];

            $ret = $this->db->query($sql, $sql_val);

            $this->cache['files'] = $ret;
        }
        return $this->cache['files'];
        
    }

    /**
     * Returns array of backlinks data
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
    public function getBackLinks()
    {
        if (!isset($this->cache['files'])) {
            $backlinks = [];
            $bl_data = $this->cfg->get("tables.{$this->tb}.backlinks");

            if (is_array($bl_data)) {
                foreach ($bl_data as $bl) {
                    list($ref_tb, $via_plg, $via_plg_fld) = \utils::csv_explode($bl, ':');
                    $ref_tb_id = $this->cfg->get("tables.$ref_tb.id_field");

                    $where = " id IN (SELECT DISTINCT id_link FROM {$via_plg} WHERE table_link = '{$ref_tb}' AND {$via_plg_fld} = {$this->id})";

                    $sql = "SELECT count(id) as tot FROM {$ref_tb} WHERE id IN (SELECT DISTINCT id_link FROM {$via_plg} WHERE table_link = '{$ref_tb}' AND {$via_plg_fld} = ?)";

                    $sql_val = [$this->id];


                    $r = $this->db->query($sql, $sql_val);
                    if ($r[0]['tot'] == 0) {
                        continue;
                    }

                    $backlinks[$ref_tb] = [
                        'tb_id' => $ref_tb,
                        'tb_stripped' => str_replace(PREFIX, null, $ref_tb),
                        "tb_label" => $this->cfg->get("tables.$ref_tb.label"),
                        'tot' => $r[0]['tot'],
                        'where' => $where,
                        'data' => $r
                    ];
                }
            }
            $this->cache['files'] = $backlinks;
        }
        return $this->cache['files'];

    }

    /**
     * Returns array with (system) links data
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
    public function getLinks()
    {
        if (!isset($this->cache['links'])) {
            $links = [];

            $links_data = $this->cfg->get("tables.{$this->tb}.link");

            if (is_array($links_data)) {
                foreach ($links_data as $ld) {
                    $where = [];
                    $values = [];
                    foreach ($ld['fld'] as $c) {
                        $where[] = " {$c['other']} = ? ";
                        $values[] = $core[$c['my']]['val'];
                    }

                    $r = $this->db->query(
                        "SELECT count(id) as tot FROM {$ld['other_tb']} WHERE " . implode($where, ' AND '),
                        $values
                    );

                    $links[$ld['other_tb']] = [
                        'tb_id' => $ld['other_tb'],
                        'tb_stripped' => str_replace(PREFIX, null, $ld['other_tb']),
                        "tb_label" => $this->cfg->get("tables.{$ld['other_tb']}.label"),
                        'tot' => $r[0]['tot'],
                        'where' => implode($where, ' AND ')
                    ];
                }
            }
            $this->cache['links'] = $links;
        }
        return $this->cache['links'];
    }

    /**
     * Returns array with plugins data
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
    public function getPlugins()
    {
        if (!isset($this->cache['plugins'])) {
            $plugins = [];

            $plg_names = $this->cfg->get("tables.{$this->tb}.plugin");
            if ($plg_names && is_array($plg_names)) {
                foreach ($plg_names as $p) {
                    $plg_data = $this->getTbRecord($p, "table_link = ? AND id_link = ?", [$this->tb, $this->id], false, true) ?: [];
                    if (empty($plg_data)) {
                        continue;
                    }
                    $indexed_plg_data = [];
                    foreach ($plg_data as $key => $row) {
                        $indexed_plg_data[$row['id']['val']];
                    }
                    // sort records using sort field, if available
                    if (in_array('sort', array_keys(reset($plg_data)))) {
                        usort($plg_data, function ($a, $b) {
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
                            "tb_label" => $this->cfg->get("tables.$p.label"),
                            "tot" => count($plg_data)
                        ],
                        "data" => $plg_data
                    ];
                }
            }
            $this->cache['plugins'] = $plugins;
        }
        return $this->cache['plugins'];
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
    private function getTbRecord(string $tb, string $sql, array $sql_val = [], bool $return_first = false, bool $return_all_fields = false)
    {
        $cfg = $this->cfg->get("tables.$tb.fields");

        $fields = $return_all_fields ? ["*"] : ["{$tb}.*"];
        $join = [];

        foreach ($cfg as $arr) {
            if ($arr['id_from_tb']) {
                $ref_tb = $arr['id_from_tb'];
                $ref_alias = uniqid('al');
                $ref_tb_fld = $this->cfg->get("tables.{$arr['id_from_tb']}.id_field");

                if ($tb === $ref_tb) {
                    continue;
                }

                array_push(
                    $fields,
                    $ref_alias .'.' . $ref_tb_fld .' AS "@' . $arr['name'] . '"'
                );

                array_push(
                    $join,
                    " LEFT JOIN {$ref_tb} AS {$ref_alias} ON {$ref_alias}.id = {$tb}.{$arr['name']} "
                );
            }
        }

        $full_sql = 'SELECT ' . implode(', ', $fields) .
            " FROM {$tb} " .
            implode(' ', $join) .
            " WHERE {$sql}";

        $r = $this->db->query($full_sql, $sql_val, 'read');

        if (!$r) {
            return [];
        }

        $ret = [];
        $return_arr = [];

        foreach ($r as $res) {
            foreach ($res as $k => $v) {
                if (strpos($k, '@') === false) {
                    $ret[$k] = [
                        'name' => $k,
                        'label' => $this->cfg->get("tables.$tb.fields.$k.label"),
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
