<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use DB\DBInterface;
use Config\Config;
use \geoPHP\geoPHP;

class Read
{
    private $db;
    private $cfg;
    private $tb;
    private $id;
    private $id_fld;

    private $cache = [];

    /**
     * Initializes class
     * Sets $app and $db
     *
     * @param DBInterface $db       DB object
     */
    public function __construct(
        int $id = null,
        string $id_fld = null,
        string $tb,
        DBInterface $db,
        Config $cfg
    ) {
        $this->id = $id;
        $this->id_fld = $id_fld;
        $this->tb = $tb;
        $this->db = $db;
        $this->cfg = $cfg;
    }

    public function getTb(): string
    {
        return $this->tb;
    }

    /**
     * Return a complete array of record data
     * @return array      Complete array of record data
     *
     * "metadata": {
     *    "tb_id": (referenced table full name),

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
                'tb_label' => $this->cfg->get("tables.{$this->tb}.label")
            ],
            'core'        => $core,
            'plugins'     => $this->getPlugin(),
            'links'       => $this->getLinks(),
            'backlinks'   => $this->getBackLinks(),
            'manualLinks' => $this->getManualLinks(),
            'files'       => $this->getFiles(),
            'geodata'     => $this->getGeodata(),
            'rs'          => $this->cfg->get("tables.{$this->tb}.rs") ? $this->getRs() : [],
            'bibliography' => $this->getBibliography(),
        ];
    }

    /**
     * Returns array with core data
     * @param string $fld   Field name, to return only a segment;
     * @param bool $return_val
     * @return array|int|string        Array of table data of int|string if $fld is set
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
            if ($this->id_fld) {
                $sql = "{$this->tb}." . $this->cfg->get("tables.{$this->tb}.id_field") . " = ?";
                $val = [$this->id_fld];
            } else {
                $sql = "{$this->tb}.id = ?";
                $val = [$this->id];
            }
            $this->cache['core'] = $this->getTbRecord($this->tb, $sql, $val, true, false);
            if(!$this->id){
                $this->id = $this->cache['core']['id']['val'];
            }
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

     *    "tb_label": (referenced table Label),
     *    "ref_id": (int),
     *    "ref_label": (string|int)
     * }
     */
    public function getManualLinks(): array
    {
        if (!isset($this->cache['manuallinks'])) {
            $manualLinks = [];
            $sql = <<<EOD
SELECT bdus_userlinks.*
  FROM bdus_userlinks
 WHERE (tb_one = ? AND
        id_one = ? AND
        tb_two != 'bdus_files') OR
       (tb_two = ? AND
        id_two = ? AND
        tb_one != 'bdus_files')
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
                        $lres = $this->db->query(
                            "SELECT {$id_fld} as label FROM {$mlt} WHERE id = ?",
                            [$mli],
                            'read'
                        );
                        $ref_val_label = $lres[0]['label'];
                    }

                    $appName    = $this->cfg->get('main.name') ?? '';
                    $tbPrefix   = $appName !== '' ? $appName . '__' : '';
                    $tbStripped = ($tbPrefix !== '' && str_starts_with($mlt, $tbPrefix))
                                    ? substr($mlt, strlen($tbPrefix))
                                    : $mlt;

                    $manualLinks[$r['id']] = [
                        "key"         => $r['id'],
                        "tb_id"       => $mlt,
                        "tb_stripped" => $tbStripped,
                        "tb_label"    => $this->cfg->get("tables.$mlt.label"),
                        "ref_id"      => $mli,
                        "ref_label"   => $ref_val_label,
                        "sort"        => $r['sort'],
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
    public function getRs(): array
    {
        if (!isset($this->cache['rs'])) {
            $res = $this->db->query(
                "SELECT id, first, second, relation FROM bdus_rs WHERE tb = ? AND (first= ? OR second = ?)",
                [$this->tb, $this->id, $this->id],
                'read'
            );

            $ret = [];

            if ($res && is_array($res)) {
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
    public function getGeodata(): array
    {
        if (!isset($this->cache['geodata'])) {
            // Query bdus_geodata directly (same pattern as getRs / getManualLinks).
            // The generic getPlugin() mechanism is NOT used here because geodata
            // is a bdus_ system table and its name no longer appears in the app config.
            $res = $this->db->query(
                "SELECT id, table_link, id_link, geometry
                   FROM bdus_geodata
                  WHERE table_link = ? AND id_link = ?",
                [$this->tb, $this->id],
                'read'
            );

            $geodata = [];
            if ($res && is_array($res)) {
                foreach ($res as $row) {
                    $entry = [
                        'id'         => ['name' => 'id',         'label' => 'ID',          'val' => (int) $row['id']],
                        'table_link' => ['name' => 'table_link', 'label' => 'Table',        'val' => $row['table_link']],
                        'id_link'    => ['name' => 'id_link',    'label' => 'Record ID',    'val' => (int) $row['id_link']],
                        'geometry'   => ['name' => 'geometry',   'label' => 'Coordinates',  'val' => $row['geometry']],
                    ];
                    try {
                        $geoPHP = geoPHP::load($row['geometry'], 'wkt');
                        $entry['geojson'] = $geoPHP->out('json');
                    } catch (\Throwable $e) {
                        $entry['geojson'] = null;
                    }
                    $geodata[(int) $row['id']] = $entry;
                }
            }
            $this->cache['geodata'] = $geodata;
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
    public function getFiles(): array
    {
        if (!isset($this->cache['files'])) {

            if ($this->tb === 'bdus_files') {
                $core = $this->getCore();
                $tmp = [];
                foreach ($core as $key => $value) {
                    $tmp[$key] = $value['val'];
                }
                $this->cache['files'] = [$tmp];
            } else {

                $sql = <<<EOD
SELECT bdus_files.*, fl.id AS link_id, fl.sort AS link_sort
FROM bdus_files
    INNER JOIN bdus_file_links AS fl
        ON fl.file_id = bdus_files.id
       AND fl.table_name = ?
       AND fl.record_id  = ?
ORDER BY fl.sort, fl.id
EOD;
                $this->cache['files'] = $this->db->query($sql, [$this->tb, $this->id]);
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
     *        "tb_label": (referenced table Label),
     *        "tot": (total number of links found),
     *        "data": [
     *          {
     *            "id": (int),
     *            "label": (string)
     *          },
     */
    public function getBackLinks(): array
    {
        if (!isset($this->cache['backlinks'])) {
            $backlinks = [];
            $bl_data = $this->cfg->get("tables.{$this->tb}.backlinks");

            if (is_array($bl_data)) {
                foreach ($bl_data as $bl) {
                    list($ref_tb, $via_plg, $via_plg_fld) = array_filter(array_map('trim', explode(':', $bl)), 'strlen');
                    $ref_tb_id = $this->cfg->get("tables.$ref_tb.id_field");

                    $r = $this->db->query(
                        "SELECT count(id) as tot FROM {$ref_tb} WHERE id IN (SELECT DISTINCT id_link FROM {$via_plg} WHERE table_link = '{$ref_tb}' AND {$via_plg_fld} = ?)",
                        [$this->id]
                    );
                    if ($r[0]['tot'] == 0) {
                        continue;
                    }
                    // Fetch the linked IDs directly for the JSON filter.
                    $linked_rows = $this->db->query(
                        "SELECT DISTINCT id_link FROM {$via_plg} WHERE table_link = '{$ref_tb}' AND {$via_plg_fld} = ?",
                        [$this->id]
                    ) ?: [];
                    $linked_ids = array_column($linked_rows, 'id_link');

                    $backlinks[$ref_tb] = [
                        'tb_id'    => $ref_tb,
                        'tb_label' => $this->cfg->get("tables.$ref_tb.label"),
                        'tot'      => $r[0]['tot'],
                        'filter'   => ['id' => ['_in' => $linked_ids]],
                        'data'     => $this->db->query(
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

     *    "tb_label": (referenced table label),
     *    "tot": (total number of links found),
     *    "where": (SQL where statement to fetch records)
     *    },
     */
    public function getLinks(): array
    {
        if (!isset($this->cache['links'])) {
            $links = [];

            $links_data = $this->cfg->get("tables.{$this->tb}.link");

            if (is_array($links_data)) {
                foreach ($links_data as $ld) {
                    $where  = [];
                    $values = [];
                    $filter = [];
                    foreach ($ld['fld'] as $c) {
                        array_push($where,  " {$c['other']} = ? ");
                        array_push($values, $this->getCore($c['my'], true));
                        // Directus-style filter: { field: { _eq: value } }
                        $filter[$c['other']] = ['_eq' => $this->getCore($c['my'], true)];
                    }

                    $r = $this->db->query(
                        "SELECT count(id) as tot FROM {$ld['other_tb']} WHERE " . implode(' AND ', $where),
                        $values
                    );
                    $tot_links = (int)$r[0]['tot'];
                    if ($tot_links > 0) {
                        $links[$ld['other_tb']] = [
                            'tb_id'    => $ld['other_tb'],
                            'tb_label' => $this->cfg->get("tables.{$ld['other_tb']}.label"),
                            'tot'      => $tot_links,
                            'filter'   => $filter,
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
    public function getPlugin(string $plugin = null, int $index = null, string $fld = null)
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
                        "tb_label" => $this->cfg->get("tables.$p.label"),
                        "tot" => count($plg_data)
                    ],
                    "data" => $plg_data
                ];
            }
            $ret[$p] = $this->cache['plugins'][$p];
        }

        if (!$plugin) {
            return $ret;
        }
        if (!isset($index)) {
            return $ret[$plugin];
        }
        if (!$fld) {
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
    /**
     * Returns Zotero bibliography links for this record.
     *
     * Each entry includes cached citation data plus the public Zotero URL
     * (null for user libraries). Items marked detached are included so the
     * UI can warn the user.
     *
     * @return array  Keyed by bdus_zotero_links.id
     */
    public function getBibliography(): array
    {
        if (!isset($this->cache['bibliography'])) {
            // Check if bdus_zotero_links exists (created by M023); cross-engine.
            try {
                $this->db->query('SELECT COUNT(*) AS cnt FROM bdus_zotero_links WHERE 1=0', [], 'read');
            } catch (\Throwable $e) {
                $this->cache['bibliography'] = [];
                return [];
            }

            $rows = $this->db->query(
                "SELECT l.id, l.lib_id, l.zotero_key, l.pages, l.notes, l.sort,
                        l.author_year, l.full_citation, l.synced_at, l.detached,
                        libs.type AS lib_type, libs.zotero_id AS lib_zotero_id,
                        libs.name AS lib_name
                   FROM bdus_zotero_links l
                   JOIN bdus_zotero_libs libs ON libs.id = l.lib_id
                  WHERE l.tb = ? AND l.record_id = ?
                  ORDER BY l.sort, l.id",
                [$this->tb, $this->id],
                'read'
            ) ?: [];

            $result = [];
            foreach ($rows as $r) {
                $zoteroUrl = ($r['lib_type'] === 'group')
                    ? "https://www.zotero.org/groups/{$r['lib_zotero_id']}/items/{$r['zotero_key']}"
                    : null;

                $result[$r['id']] = [
                    'id'           => (int) $r['id'],
                    'lib_id'       => (int) $r['lib_id'],
                    'lib_name'     => $r['lib_name'],
                    'zotero_key'   => $r['zotero_key'],
                    'pages'        => $r['pages'],
                    'notes'        => $r['notes'],
                    'sort'         => $r['sort'],
                    'author_year'  => $r['author_year'],
                    'full_citation' => $r['full_citation'],
                    'synced_at'    => $r['synced_at'],
                    'detached'     => (bool) $r['detached'],
                    'zotero_url'   => $zoteroUrl,
                ];
            }

            $this->cache['bibliography'] = $result;
        }

        return $this->cache['bibliography'];
    }

    private function getTbRecord(string $tb, string $sql, array $sql_val = [], bool $return_first = false, bool $return_all_fields = false): array
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
                    $ref_alias . '.' . $ref_tb_fld . ' AS "@' . $arr['name'] . '"'
                );

                array_push(
                    $join,
                    " LEFT JOIN {$ref_tb} AS {$ref_alias} ON {$ref_alias}.id = {$tb}.{$arr['name']} "
                );

                if ($return_all_fields) {
                    $joined_flds = $this->cfg->get("tables.$ref_tb.fields.*.name") ?: [];
                    unset($joined_flds['id']);
                    unset($joined_flds[$arr['name']]);
                    $joined_flds = array_map(function ($e) use ($ref_alias) {
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
