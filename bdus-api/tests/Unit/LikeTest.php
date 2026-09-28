<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SQL\Like;

/**
 * SQL\Like::operator() — LIKE on SQLite/MySQL, ILIKE on PostgreSQL (issue #73).
 */
class LikeTest extends TestCase
{
    #[DataProvider('engines')]
    public function testOperator(string $engine, string $like, string $notLike): void
    {
        $this->assertSame($like, Like::operator($engine));
        $this->assertSame($notLike, Like::operator($engine, true));
    }

    public static function engines(): array
    {
        return [
            // engine => [engine, LIKE, NOT LIKE]
            'sqlite: unchanged'        => ['sqlite', 'LIKE',  'NOT LIKE'],
            'mysql: unchanged'         => ['mysql',  'LIKE',  'NOT LIKE'],
            'pgsql: case-insensitive'  => ['pgsql',  'ILIKE', 'NOT ILIKE'],
            'unknown engine: plain'    => ['oracle', 'LIKE',  'NOT LIKE'],
            'empty engine: plain'      => ['',       'LIKE',  'NOT LIKE'],
        ];
    }
}
