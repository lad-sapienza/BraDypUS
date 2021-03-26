<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use DB\DBInterface;
use Config\Config;

class Read
{
    private $db;
    private $cfg;
    private $tb;
    private $id;
    private $is_id_fld;

    private $cache = [];

    /**
     * Initializes class
     * Sets $app and $db
     *
     * @param DBInterface $db       DB object
     */
    public function __construct(int $id, bool $is_id_fld = false, string $tb, DBInterface $db, Config $cfg)
    {
        $this->id = $id;
        $this->is_id_fld = $is_id_fld;
        $this->tb = $tb;
        $this->db = $db;
        $this->cfg = $cfg;
        
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
     * "plugins":     { see $this->getPlugin for docs     },
     * "links":       { see $this->getLinks for docs       },
     * "backlinks":   { see $this->getBackLinks for docs   },
     * "manualLinks": { see $this->getManualLinks for docs },
     * "files":       { see $this->getFiles for docs       },
     * "rs":          { see $this->getRs for docs          }
     */
    public function getFull(): array
    {
        $core = $this->getCore() ?: [];

        return [
            'metadata' => [
                'tb_id' => $this->tb,
                'rec_id' => $core['id'],
                'tb_stripped' => str_replace(PREFIX, null, $this->tb),
                'tb_label' => $this->cfg->get("tables.{$this->tb}.label")
            ],
            'core'       => $core,
            'plugins'    => $this->getPlugin(),
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
   * @param string $fld   Field name, to return only a segment;
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
            if ($this->is_id_fld){
                $sql = "{$this->tb}." . $this->cfg->get("tables.{$this->tb}.id_field"). " = ?";
            } else {
                $sql = "{$this->tb}.id = ?";
            }
            $this->cache['core'] = $this->getTbRecord($this->tb, $sql, [$this->id], true, false);
        }
        if (!$fld) {
            return $this->cache['core'];
        } elseif ($return_val) {
            return $this->cache['core'][$fld]['val'];
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
 ORDER BY sort, id
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
                    if ($this->tb === $r['tb_one'] && $this->id === (int)$r['id_one']) {
                        $mlt = $r['tb_two'];
                        $mli = $r['id_two'];
                    } elseif ($this->tb === $r['tb_two'] && $this->id === (int)$r['id_two']) {
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
    public function getRs() : array
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
     * Returns array of geodata or empty array, if geodata are not available
     * [
     *  {
     *      "(field id)": {
     *          "id": (row id),
     *          "table_link": (row id),
     *      }
     *  }
     * ]
     * @return array
     */
    public function getGeodata() : array
    {
        if (!isset($this->cache['geodata'])){
            $plugins = $this->getPlugin();
            if (
                    isset($plugins['geogata']) 
                &&  isset($plugins['geogata']['data'])
                &&  is_array($plugins['geogata']['data'])
                &&  !empty($plugins['geogata']['data'])
            ){
                $this->cache['geodata'] = $plugins['geogata']['data'];
            } else {
                $this->cache['geodata'] = [];
            }
        }
        return $this->cache['geodata'];
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
    public function getFiles() : array
    {
        if (!isset($this->cache['files'])) {

            $prefix = PREFIX;

            if ($this->tb === $prefix . 'files' ){
                $core = $this->getCore();
                $tmp = [];
                foreach ($core as $key => $value) {
                    $tmp[$key] = $value['val'];
                }
                $this->cache['files'] = [$tmp];
            } else {

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
ORDER BY ul.sort
EOD;
                $sql_val = [
                    $this->tb,
                    $this->id,
                    $this->tb,
                    $this->id
                ];

                $this->cache['files'] = $this->db->query($sql, $sql_val);

            }

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
    public function getBackLinks() : array
    {
        if (!isset($this->cache['backlinks'])) {
            $backlinks = [];
            $bl_data = $this->cfg->get("tables.{$this->tb}.backlinks");

            if (is_array($bl_data)) {
                foreach ($bl_data as $bl) {
                    list($ref_tb, $via_plg, $via_plg_fld) = \utils::csv_explode($bl, ':');
                    $ref_tb_id = $this->cfg->get("tables.$ref_tb.id_field");

                    $r = $this->db->query(
                        "SELECT count(id) as tot FROM {$ref_tb} WHERE id IN (SELECT DISTINCT id_link FROM {$via_plg} WHERE table_link = '{$ref_tb}' AND {$via_plg_fld} = ?)",
                        [$this->id]
                    );
                    if ($r[0]['tot'] == 0) {
                        continue;
                    }
                    $backlinks[$ref_tb] = [
                        'tb_id' => $ref_tb,
                        'tb_stripped' => str_replace(PREFIX, null, $ref_tb),
                        "tb_label" => $this->cfg->get("tables.$ref_tb.label"),
                        'tot' => $r[0]['tot'],
                        'where' => "id|in|{@{$via_plg}~[id_link|distinct~table_link|=|{$ref_tb}||and|{$via_plg_fld}|=|^{$this->id}}",
                        'data' => $this->db->query(
                            "SELECT id, {$ref_tb_id} as label FROM {$ref_tb} WHERE id IN (SELECT DISTINCT id_link FROM {$via_plg} WHERE table_link = '{$ref_tb}' AND {$via_plg_fld} = ?)",
                            [$this->id]
                        )
                    ];
                }
            }
            $this->cache['backlinks'] = $backlinks;
        }
        return $this->cache['backlinks'];
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
    public function getLinks() : array
    {
        if (!isset($this->cache['links'])) {
            $links = [];

            $links_data = $this->cfg->get("tables.{$this->tb}.link");

            if (is_array($links_data)) {
                foreach ($links_data as $ld) {
                    $where = [];
                    $values = [];
                    $short_sql = [];
                    foreach ($ld['fld'] as $c) {
                        array_push($where, " {$c['other']} = ? ");
                        array_push($values, $this->getCore($c['my'], true));
                        array_push($short_sql, "{$c['other']}|=|" . $this->getCore($c['my'], true));
                    }

                    $r = $this->db->query(
                        "SELECT count(id) as tot FROM {$ld['other_tb']} WHERE " . implode(' AND ', $where),
                        $values
                    );
                    $tot_links = (int)$r[0]['tot'];
                    if ($tot_links > 0 ) {
                        $links[$ld['other_tb']] = [
                            'tb_id' => $ld['other_tb'],
                            'tb_stripped' => str_replace(PREFIX, null, $ld['other_tb']),
                            "tb_label" => $this->cfg->get("tables.{$ld['other_tb']}.label"),
                            'tot' => $tot_links,
                            'where' => implode('||and|', $short_sql)
                        ];
                    }
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
    public function getPlugin( string $plugin = null, int $index = null, string $fld = null )
    {
        $required = $plugin ? [$plugin] : ($this->cfg->get("tables.{$this->tb}.plugin") ?: []);

        $ret = [];

        foreach ($required as $p) {
            if (!isset($this->cache['plugins'][$p])) {
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

                $this->cache['plugins'][$p] = [
                    "metadata" => [
                        "tb_id" => $p,
                        "tb_stripped" => str_replace(PREFIX, null, $p),
                        "tb_label" => $this->cfg->get("tables.$p.label"),
                        "tot" => count($plg_data)
                    ],
                    "data" => $plg_data
                ];
            }
            $ret[$p] = $this->cache['plugins'][$p];
        }

        if (!$plugin){
            return $ret;
            
        }
        if (!isset($index)){
            return $ret[$plugin];
        }
        if (!$fld){
            return $ret[$plugin]['data'][$index];
        }
        return $ret[$plugin]['data'][$index][$fld]['val'];
    }

    /**
     * [getTbRecord description]
     * @param  string  $tb           Table name
     * @param  string  $sql          Where SQl statement
     * @param  array   $sql_val      binding data
     * @param  boolean $return_first If true only the first row of the results will be returned
     * @param  boolean $return_all_fields If true all fields will be returned, otherwise only table fields
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
    private function getTbRecord(string $tb, string $sql, array $sql_val = [], bool $return_first = false, bool $return_all_fields = false) : array
    {
        $cfg = $this->cfg->get("tables.$tb.fields");
        $fields = ["{$tb}.*"];
        $join = [];

        foreach ($cfg as $arr) {
            if (isset($arr['id_from_tb'])) {
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

                if ($return_all_fields){
                    $joined_flds = $this->cfg->get("tables.$ref_tb.fields.*.name");
                    unset($joined_flds['id']);
                    unset($joined_flds[$arr['name']]);
                    $joined_flds = array_map(function ($e) use ($ref_alias){
                        return $ref_alias . "." . $e;
                    }, $joined_flds);
                    
                    $fields = array_merge(
                        $fields,
                        $joined_flds
                    );
                }
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
