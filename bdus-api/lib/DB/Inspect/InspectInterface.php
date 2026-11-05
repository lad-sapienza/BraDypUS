<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB\Inspect;

/**
 * Interface to interact with database structure
 */
interface InspectInterface
{
  /**
   * Checks if a table OR a view with this name exists in the current
   * database/schema (same semantics on every engine).
   */
  public function tableExists(string $tb): bool;

  /**
   * True when $tb exists and is a (non-materialised) SQL view.
   */
  public function isView(string $tb): bool;

  /**
   * Gets list of table columns 
   *
   * @param string $tb    Table name
   * @return array array of arrays with column data
   *                  example: [ [ 'fld' => "fld_name", 'type' => 'column_type' ], [ ...etc... ] ]
   */
  public function tableColumns(string $tb): array;

  /**
   * Returns the list of base tables contained in database
   * (views are NOT included, on any engine).
   *
   * @return array
   */
  public function getAllTables(): array;
}
