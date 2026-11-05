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
   * Every table and view of the current database/schema, with what is needed
   * to decide whether (and how) it can be activated as a BraDypUS table.
   * System objects (`bdus_*`, engine internals, SQLite virtual tables and
   * their shadow tables) are left out.
   *
   * @return array<int, array{
   *   name: string,
   *   kind: 'table'|'view',
   *   columns: array<int, array{fld: string, type: string, pk: bool}>,
   *   sql: ?string,           view definition (null for tables)
   *   view_writable: ?string  'none'|'probe'|'trigger' (null for tables)
   * }>
   */
  public function listObjects(): array;

  /**
   * Single-column FOREIGN KEYs declared on a table, as the database knows them.
   * Composite keys are skipped (BraDypUS relations are single-column).
   *
   * @return array<int, array{from_col: string, to_tb: string, to_col: string, on_delete: string, on_update: string}>
   */
  public function foreignKeys(string $tb): array;

  /**
   * Returns the list of base tables contained in database
   * (views are NOT included, on any engine).
   *
   * @return array
   */
  public function getAllTables(): array;
}
