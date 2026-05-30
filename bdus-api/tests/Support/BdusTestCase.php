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

    // ── Boot once per test class ──────────────────────────────────────────
    public static function setUpBeforeClass(): void
    {
        // Simulate a logged-in super-admin so \Auth\Authorization::can() passes.
        // privilege = 1 satisfies every privilege level check (all are < N where N >= 2).
        \Auth\CurrentUser::set([
            'id'        => 1,
            'name'      => 'Test Admin',
            'email'     => 'test@example.com',
            'privilege' => 1,
            'app'       => 'test',
        ]);

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
            __DIR__ . '/../fixtures/cfg/'
        );

        // Point the template Loader at the fixtures directory so controller
        // integration tests can resolve fixture templates without a real project dir.
        \Template\Loader::setProjectsRoot(__DIR__ . '/../fixtures/');

        // Schema + seed data
        static::createSchema();
        static::seedData();
    }

    // ── Schema ────────────────────────────────────────────────────────────
    protected static function createSchema(): void
    {
        static::$db->execInTransaction('
            CREATE TABLE items (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                creator     TEXT,
                name        TEXT,
                description TEXT,
                status      TEXT,
                score       TEXT,
                email_addr  TEXT,
                geo_data    TEXT,
                ref_code    TEXT,
                birth_date  TEXT,
                lang_code   TEXT,
                category    TEXT
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE bdus_vocabularies (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                voc  TEXT    NOT NULL,
                def  TEXT    NOT NULL,
                sort INTEGER
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE tags (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                label      TEXT,
                id_link    INTEGER,
                table_link TEXT
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE bdus_log (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT NOT NULL,
                level   INTEGER NOT NULL,
                message TEXT NOT NULL,
                time    INTEGER NOT NULL
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE bdus_versions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                userid      INTEGER NOT NULL,
                time        INTEGER NOT NULL,
                tb          TEXT    NOT NULL,
                rowid       INTEGER NOT NULL,
                content     TEXT    NOT NULL,
                editsql     TEXT,
                editvalues  TEXT,
                operation   TEXT    NOT NULL DEFAULT \'update\'
            )
        ');

        // ── System tables required by Record\Read::getFull() ──────────────
        static::$db->execInTransaction('
            CREATE TABLE bdus_userlinks (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                tb_one TEXT    NOT NULL,
                id_one INTEGER NOT NULL,
                tb_two TEXT    NOT NULL,
                id_two INTEGER NOT NULL,
                sort   INTEGER
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE bdus_rs (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                tb       TEXT    NOT NULL,
                first    TEXT    NOT NULL,
                second   TEXT    NOT NULL,
                relation INTEGER NOT NULL
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE bdus_geodata (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                table_link TEXT    NOT NULL,
                id_link    INTEGER NOT NULL,
                geometry   TEXT    NOT NULL
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE bdus_files (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                creator     TEXT    NOT NULL,
                ext         TEXT    NOT NULL,
                keywords    TEXT,
                description TEXT,
                printable   INTEGER,
                filename    TEXT    NOT NULL
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE bdus_file_links (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id     INTEGER NOT NULL,
                table_name  TEXT    NOT NULL,
                record_id   INTEGER NOT NULL,
                sort        INTEGER
            )
        ');

        static::$db->execInTransaction('
            CREATE TABLE bdus_migrations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    NOT NULL UNIQUE,
                applied_at INTEGER NOT NULL
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
                "INSERT INTO items (creator, name, description, status)
                 VALUES ('admin', '$name', '$desc', '$status')"
            );
        }

        // A couple of tags linked to item 1
        static::$db->execInTransaction(
            "INSERT INTO tags (label, id_link, table_link)
             VALUES ('tag-a', 1, 'items'), ('tag-b', 1, 'items')"
        );

        // A file (image) and a document linked to item 1 via bdus_file_links
        static::$db->execInTransaction(
            "INSERT INTO bdus_files (id, creator, ext, keywords, description, printable, filename)
             VALUES (1, 'admin', 'jpg', 'photo', 'A photo', 1, 'photo'),
                    (2, 'admin', 'pdf', 'doc',   'A document', 0, 'document')"
        );
        static::$db->execInTransaction(
            "INSERT INTO bdus_file_links (file_id, table_name, record_id, sort)
             VALUES (1, 'items', 1, 1),
                    (2, 'items', 1, 2)"
        );

        // A manual link between item 1 and item 2 (record↔record, not a file link)
        static::$db->execInTransaction(
            "INSERT INTO bdus_userlinks (tb_one, id_one, tb_two, id_two, sort)
             VALUES ('items', 1, 'items', 2, 1)"
        );

        // An RS entry referencing item 1 (first='1' so Read::getRs() can find it by id=1)
        static::$db->execInTransaction(
            "INSERT INTO bdus_rs (tb, first, second, relation) VALUES ('items', '1', '2', 1)"
        );

        // Vocabulary entries for the 'test_cat' set (used by RecordCtrlFieldOptionsTest)
        static::$db->execInTransaction(
            "INSERT INTO bdus_vocabularies (voc, def, sort) VALUES
               ('test_cat', 'Cat-A', 1),
               ('test_cat', 'Cat-B', 2),
               ('test_cat', 'Cat-C', 3),
               ('other_set', 'Other-X', 1)"
        );

        // A couple of log entries
        $now = time();
        static::$db->execInTransaction(
            "INSERT INTO bdus_log (channel, level, message, time)
             VALUES
               ('test', 200, 'Info message',  " . ($now - 3600) . "),
               ('test', 400, 'Error message', " . ($now - 100)  . ")"
        );
    }

    // ── Auth helpers ──────────────────────────────────────────────────────

    /**
     * Change the privilege of the simulated logged-in user for a single assertion,
     * then restore it to the default super-admin value (1).
     *
     * Usage:
     *   $this->setPrivilege(99);   // low-privilege user
     *   // … assertion expecting 'error' …
     *   $this->setPrivilege(1);    // restore super-admin
     */
    protected function setPrivilege(int $privilege): void
    {
        \Auth\CurrentUser::set([
            'id'        => 1,
            'name'      => 'Test Admin',
            'email'     => 'test@example.com',
            'privilege' => $privilege,
            'app'       => 'test',
        ]);
    }

    // ── Controller helpers ────────────────────────────────────────────────

    /**
     * Instantiate a controller with injected dependencies.
     *
     * @param string $class  FQCN e.g. 'Bdus\Controllers\Record', or legacy short name e.g. 'Bdus\\Controllers\\Record'
     * @param array  $get    Simulated $_GET params (obj/method not needed)
     * @param array  $post   Simulated $_POST params
     */
    protected function makeController(string $class, array $get = [], array $post = []): \Bdus\Controller
    {
        $ctrl = new $class($get, $post, array_merge($get, $post));
        $ctrl->setDB(static::$db);
        $ctrl->setCfg(static::$cfg);
        $ctrl->setLog(static::$log);
        return $ctrl;
    }

    /**
     * Call a public controller method, capture its JSON output, decode and return.
     *
     * @throws \JsonException on invalid JSON
     */
    protected function callController(\Bdus\Controller $ctrl, string $method): array
    {
        ob_start();
        $ctrl->$method();
        $raw = ob_get_clean();
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
