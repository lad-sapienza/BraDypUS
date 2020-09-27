<?php
namespace SQL;

class QueryObject
{
    private $obj;

    public function __construct()
    {
        $this->obj = [
            'tb'        => [], // [ tb, alias ]
            'fields'    => [], // [ [tb, fld, alias], [...] ]
            'joins'     => [], // [ [tb, alias, on ], [...] ]
            'where'     => [], // [ [null, field, operator, value], [ connector, field, operator, value], [...] ]
            'group'     => [], // [ fld1, fld2, ... ]
            'order'     => [], // [ [fld,ASC|DESC], [...] ]
            'limit'     => null, // [ tot: n, offset: n ]
            'values'    => [], // [val, val, val]
        ];
    }

    public function get(string $index = null)
    {
        if (!$index) {
            return $this->obj;
        } else if ( array_key_exists($index, $this->obj) ) {
            return $this->obj[$index];
        } else {
            return false;
        }
    }

    public function setTb( string $tb, string $alias = null) : void
    {
        $this->obj['tb'] = [$tb, $alias];
    }
    
    public function setField(string $tb = null, string $fld, string $alias = null): void
    {
        array_push ( $this->obj['fields'], [$tb, $fld, $alias] );
    }
    
    public function setJoin( string $tb, string $tb_alias = null, array $on)
    {
        $found = false;
        foreach ($this->obj['joins'] as $j) {
            if ($j == [
                $tb,
                $tb_alias,
                $on
            ]){
                $found = true;
            }
        }
        if (!$found) {
            array_push($this->obj['joins'], [
                trim($tb),
                $tb_alias,
                $on
            ]);
        }
    }
    
    public function setWherePart(string $connector = null, string $fld, string $operator, string $val)
    {
        if ( count($this->obj['where']) > 0 && !$connector ) {
            throw new \Exception("Connector is required for query parts other than first");
        }

        array_push( $this->obj['where'] , [
            $connector,
            $fld,
            $operator,
            $val
        ]);
    }

    public function setWhereValues( array $values )
    {
        $this->obj['values'] = array_merge($this->obj['values'], $values);
    }

    public function setOrderFld(string $fld, string $dir)
    {
        array_push( $this->obj['order'], [$fld, $dir]);
    }

    public function setLimit(int $tot, int $offset)
    {
        $this->obj['limit'] = ['tot' => $tot, 'offset' => $offset];
    }

    public function setGroupFld( string $fld )
    {
        array_push( $this->obj['group'], $fld);
    }

}