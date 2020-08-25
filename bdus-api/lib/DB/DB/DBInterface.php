<?php

namespace DB\DB;

/**
 * Interface to interact with database structure
 */
interface DBInterface
{
    public function getApp(): string;

    public function getEngine(): string;

    public function execInTransaction(string $sql): bool;
    
    public function exec(string $sql): bool;
    
    public function backupBeforeEdit(string $table, int $id, string $query, array $values = []);

    public function query(string $query, array $values = null, string $type = null, bool $fetch_style = false );

    public function beginTransaction();

    public function commit();

    public function rollBack();

}