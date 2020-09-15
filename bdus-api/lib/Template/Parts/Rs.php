<?php

namespace Tempate\Parts;

use SQL\SafeQuery;
use Config\Config;

class Rs
{
    public static function showAll( string $context, string $tb, string $rs_fld_val) : string
    {
        return '<div class="showRS" data-context="' . $context. '" data-table="' . $tb . '" data-id="' . $rs_fld_val . '"></div>';
    }
}