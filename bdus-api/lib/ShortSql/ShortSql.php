<?php
namespace ShortSql;

/**
 * Parses shortSql query syntax:
 *      Blocks are separated by tildes (~)
 *      Blocks order is not important
 *      - @table:alias               => Required. Alias is not supported yet (22.12.2019)
 *      - [field:Alias,fieldn:AliasN => Optional, default: *. List of fields to fetch, separated by commas. 
 *                                      Alias is optional. 
 *      - +tbname:Alias||onStatement => Optional. Multiple. Join statement
 *                                      Each statement if made of two parts separated by a double pipe ||. 
 *                                      The first element is the table name and optionally the alias. Alias is not supported yet (22.12.2019)
 *                                      The second part is the On statement expressed as Where statement
 *      - ?where statements          => Different where statements are separated by double pipe, ||
 *                                      Where statements other than first must contain the connector as first element.
 *                                      Where elements are separate by single pipes, |
 *                                      Each where statement contains a field, an operator and a value
 *                                      Field may be provided as field, field:alias, table.field or table.field:alias
 *                                      If value starts with ^, the value will not binded nor escaped by quotes as string: it is assumed to be a table field
 *      - >field:order               => Optional. Multiple. Sorting options separated by colon
 *                                      First element is field name. it should be e valid field name
 *                                      Second element is optional, Default: ASC. The sorting direction: ASC od DESC (case insensitive)
 *      - -tot:offset                => Optional. Limit statement, semicolon separated
 *                                      First element is the total numer of rows to fetch. Should be a numeral
 *                                      Second element, optional, is the offset
 *      - *field1,fieldn             => Optional. Group statement. Comma separated list of fields to use for grouping
 *                                      Each field shoul be a valid field and may be provided as table.field or field.
 * @uses ShortSql\ParseParts
 * @uses cfg
 * @uses PREFIX
 */
class ShortSql
{
    /**
     * Parses string and returns array of data:
     *  'tb'        => $tb,
     *  'fields'    => $fields,
     *  'join'      => $join,
     *  'where'     => $where,
     *  'group'     => $group,
     *  'sort'      => $sort,
     *  'rows'      => $rows,
     *  'offset'    => $offset,
     *  'values'    => $values
     * If $pagination_limit is provided and NO Limit statement is given, the first one will be appended to the query
     *
     * @param String $str
     * @param String $pagination_limit
     * @return array
     */
    public static function getData($str, $pagination_limit = false)
    {
        return self::parseFullString($str, $pagination_limit);
    }

    /**
     * Parses string and returns array containing full SQL statement and value bindings.
     * If $pagination_limit is provided and NO Limit statement is given, the first one will be appended to the query
     *
     * @param String $str
     * @return Array
     */
    public static function getSQLAndValues($str, $pagination_limit = false)
    {
        $data = self::parseFullString($str, $pagination_limit);

        return [
            trim( join(' ', [
                "SELECT",
                join(', ', $data['fields']),
                "FROM",
                "`{$data['tb']}`", // . ($data['tbAlias'] ? " AS `{$data['tbAlias']}`" : ''),
                join(' ', $data['join']),
                "WHERE",
                $data['where'],
                $data['group']  ? ' GROUP BY ' . implode(', ', $data['group']) : false,
                $data['sort']   ? ' ORDER BY ' . implode(', ', $data['sort']) : false,
                !is_null($data['rows'])   ? ' LIMIT ' . $data['rows'] . ($data['offset'] ? ', ' . $data['offset'] : '') : false
            ])),
            $data['values'],
            $data['tb']
        ];
    }

    /**
     * Parses and returns Where part of a SQl string
     *
     * @param String $where_str     Where string
     * @param String $tb            Reference table name
     * @return Array                First index is SQL where and second array of binded data
     */
    public function getWhere($where_str, $tb)
    {
        return ParseParts::Where($where_str, $tb);
    }

    /**
     * Parses ShortSql string to array: does all the job!
     *
     * @param String $str               ShortSql string
     * @param boolean $pagination_limit Default pagination limit
     * @throws \Exception
     * @return Array
     */
    private static function parseFullString($str, $pagination_limit = false)
    {
        $parts = explode('~', $str);

        foreach ($parts as $part) {

            // table name
            if($part[0] === '@') {
                $tb = substr($part, 1);
            
            // Field list
            } elseif ($part[0] === '[') {
                $fields_arr = explode(',', substr($part, 1));
            
            // JOINS
            } elseif ($part[0] === '+') {
                $join_arr[] = substr($part, 1);
            
            // Where
            } elseif ($part[0] === '?') {
                $where_str = substr($part, 1);

            // ORDER
            } elseif ($part[0] === '>') {
                $sort_arr[] = substr($part, 1);

            // LIMIT
            } elseif ($part[0] === '-') {
                $limit_str = substr($part, 1);

            // GROUP
            } elseif ($part[0] === '*') {
                $group_str = substr($part, 1);
            }
        }

        // If no limit is defined && pagination limit is provided, add the limit from pagination
        if (!$limit_str && $pagination_limit) {
            $limit_str = $pagination_limit;
        }

        // validate $tb
        list($tb, $tbAlias) = ParseParts::Tb($tb);

        // validate $fields: set to * if not available
        $fields = ParseParts::FieldList($fields_arr, $tb);

        $join = ParseParts::JoinArr($join_arr);
        
        list($where, $values, $plg_joins) = ParseParts::Where($where_str, $tb);

        if (is_array($plg_joins) ){
            foreach ($plg_joins as $j) {
                $join[$j['tb']] = $j['string'];
            }
        }

        $sort = ParseParts::OrderArr($sort_arr, $tb);
        
        list($rows, $offset) = ParseParts::LimitStr($limit_str);

        $group = ParseParts::GroupStr($group_str, $tb);

        return [
            'tb'        => $tb,
            'tbAlias'   => $tbAlias,
            'fields'    => $fields,
            'join'      => $join,
            'where'     => $where,
            'group'     => $group,
            'sort'      => $sort,
            'rows'      => $rows,
            'offset'    => $offset,
            'values'    => $values
        ];
    }
}