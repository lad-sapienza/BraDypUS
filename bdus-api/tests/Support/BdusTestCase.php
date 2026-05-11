<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use DB\DB;
use Config\Config;
use Adbar\Dot;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Base class for all BraDypUS tests.
 *
 * Provides:
 *  - $db   : DB instance backed by a fresh in-memory SQLite DB
 *  - $cfg  : Config instance loaded from tests/fixtures/cfg/
 *  - $log  : silent Monolog logger (NullHandler)
 *  - prefix: 'test__'
 *
 * The in-memory DB is recreated for every test class (setUpBeforeClass),
 * so tests within the same class share state (fast), but classes are isolated.
 *
 * Helper methods:
 *  - makeController(string $class, array $get, array $post): Controller
 *  - callController(Controller $ctrl, string $method): array   (decoded JSON)
 */
abstract class BdusTestCase extends TestCase
{
    protected static DB     $db;
    protected static Config $cfg;
    protected static Logger $log;
    protected static string $prefix = 'test__';

    // ── Boot once per test class ──────────────────────────────────────────
    public static function setUpBeforeClass(): void
    {
        // Simulate a logged-in super-admin so utils::canUser() passes.
        // privilege = 1 satisfies every privilege level check (all are < N where N >= 2).
        $_SESSION['user'] = [
            'id'        => 1,
            'name'      => 'Test Admin',
            'privilege' => 1,
        ];

        // Silent logger
        static::$log = new Logger('test');
        static::$log->pushHandler(new NullHandler());

        // In-memory SQLite DB via custom_connection
        static::$db = new DB('bdus_test', [
            'db_engine' => 'sqlite',
            'db_path'   => ':memory:',
        ]);
        static::$db->setLog(static::$log);

        // Config from fixture files
        $dot = new Dot();
        static::$cfg = new Config(
            $dot,
            __DIR__ . '/../fixtures/cfg/',
            static::$prefix
        );

        // Schema + seed data
        static::createSchema();
        static::seedData();
    }

    // ── Schema ────────────────────────────────────────────────────────────
    protected static function createSchema(): void
    {
        static::$db->execInTransaction('
            CREATE TABLE test__items (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                creator     TEXT,
                name        TEXT,
                description TEXT,
                status      TEXT
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE test__tags (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                label      TEXT,
                id_link    INTEGER,
                table_link TEXT
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE test__log (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT NOT NULL,
                level   INTEGER NOT NULL,
                message TEXT NOT NULL,
                time    INTEGER NOT NULL
            )
        ');

        // ── System tables required by Record\Read::getFull() ──────────────
        static::$db->execInTransaction('
            CREATE TABLE test__userlinks (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                tb_one TEXT    NOT NULL,
                id_one INTEGER NOT NULL,
                tb_two TEXT    NOT NULL,
                id_two INTEGER NOT NULL,
                sort   INTEGER
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE test__rs (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                tb       TEXT    NOT NULL,
                first    TEXT    NOT NULL,
                second   TEXT    NOT NULL,
                relation INTEGER NOT NULL
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE test__geodata (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                table_link TEXT    NOT NULL,
                id_link    INTEGER NOT NULL,
                geometry   TEXT    NOT NULL
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE test__files (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                creator     TEXT    NOT NULL,
                ext         TEXT    NOT NULL,
                keywords    TEXT,
                description TEXT,
                printable   INTEGER,
                filename    TEXT    NOT NULL
            )
        ');
    }

    // ── Seed ──────────────────────────────────────────────────────────────
    protected static function seedData(): void
    {
        $items = [
            ['Alpha item',   'First description',  'active'],
            ['Beta item',    'Second description', 'inactive'],
            ['Gamma item',   'Third description',  'active'],
            ['Delta thing',  'Fourth description', 'pending'],
            ['Epsilon thing','Fifth description',  'active'],
        ];
        foreach ($items as $i => [$name, $desc, $status]) {
            static::$db->execInTransaction(
                "INSERT INTO test__items (creator, name, description, status)
                 VALUES ('admin', '$name', '$desc', '$status')"
            );
        }

        // A couple of tags linked to item 1
        static::$db->execInTransaction(
            "INSERT INTO test__tags (label, id_link, table_link)
             VALUES ('tag-a', 1, 'test__items'), ('tag-b', 1, 'test__items')"
        );

        // A file (image) and a document linked to item 1 via userlinks
        static::$db->execInTransaction(
            "INSERT INTO test__files (id, creator, ext, keywords, description, printable, filename)
             VALUES (1, 'admin', 'jpg', 'photo', 'A photo', 1, 'photo'),
                    (2, 'admin', 'pdf', 'doc',   'A document', 0, 'document')"
        );
        static::$db->execInTransaction(
            "INSERT INTO test__userlinks (tb_one, id_one, tb_two, id_two, sort)
             VALUES ('test__files', 1, 'test__items', 1, 1),
                    ('test__files', 2, 'test__items', 1, 2)"
        );

        // A manual link between item 1 and item 2 (not a file link)
        // userlinks ids 1,2 are the file links above; this becomes id 3.
        static::$db->execInTransaction(
            "INSERT INTO test__userlinks (tb_one, id_one, tb_two, id_two, sort)
             VALUES ('test__items', 1, 'test__items', 2, 1)"
        );

        // An RS entry referencing item 1 (first='1' so Read::getRs() can find it by id=1)
        static::$db->execInTransaction(
            "INSERT INTO test__rs (tb, first, second, relation) VALUES ('test__items', '1', '2', 1)"
        );

        // A couple of log entries
        $now = time();
        static::$db->execInTransaction(
            "INSERT INTO test__log (channel, level, message, time)
             VALUES
               ('test', 200, 'Info message',  " . ($now - 3600) . "),
               ('test', 400, 'Error message', " . ($now - 100)  . ")"
        );
    }

    // ── Controller helpers ────────────────────────────────────────────────

    /**
     * Instantiate a controller with injected dependencies.
     *
     * @param string $class  e.g. 'record_ctrl', 'search_ctrl'
     * @param array  $get    Simulated $_GET params (obj/method not needed)
     * @param array  $post   Simulated $_POST params
     */
    protected function makeController(string $class, array $get = [], array $post = []): \Controller
    {
        $ctrl = new $class($get, $post, array_merge($get, $post));
        $ctrl->setDB(static::$db);
        $ctrl->setCfg(static::$cfg);
        $ctrl->setLog(static::$log);
        $ctrl->setPrefix(static::$prefix);
        return $ctrl;
    }

    /**
     * Call a public controller method, capture its JSON output, decode and return.
     *
     * @throws \JsonException on invalid JSON
     */
    protected function callController(\Controller $ctrl, string $method): array
    {
        ob_start();
        $ctrl->$method();
        $raw = ob_get_clean();
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
