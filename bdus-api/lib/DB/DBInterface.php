<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

 
namespace DB;

/**
 * Interface to interact with database structure
 */
interface DBInterface
{
    public function getApp(): string;

    public function getEngine(): string;

    public function execInTransaction(string $sql): bool;
    
    public function exec(string $sql): bool;
    
    public function saveSnapshot(string $tb, int $id, array $content, string $operation = 'update'): void;

    public function query(string $query, array $values = null, string $type = null, bool $fetch_style = false );

    public function beginTransaction();

    public function commit();

    public function rollBack();

    public function hasSpatialExtension(): bool;

}