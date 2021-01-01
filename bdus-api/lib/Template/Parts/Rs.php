<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */


namespace Template\Parts;

use SQL\SafeQuery;
use Config\Config;

class Rs
{
    public static function showAll( string $context, string $tb, string $rs_fld_val) : string
    {
        // Remove lazy loading
        return '<div class="showRS" data-context="' . $context. '" data-table="' . $tb . '" data-id="' . $rs_fld_val . '"></div>';
    }
}