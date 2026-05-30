<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\ErrorHandler;
use DB\LogDBHandler;
use DB\DB;

/**
 * Application kernel: initialises infrastructure (DB, logger, migrations)
 * then hands off to Dispatcher for controller resolution and DI.
 *
 * bootstrap.php must have run first so that APP / DEBUG_ON are defined
 * and Auth\CurrentUser is populated from the JWT (if present).
 */
class App
{
    private ?DB    $db  = null;
    private Logger $log;

    public function start(): void
    {
        if (defined('APP')) {
            $this->db = new DB(APP);
        }

        $this->setupLogger();

        if ($this->db) {
            \DB\System\Migrate::maybeRemovePrefix($this->db, $this->log);
            \DB\System\Migrate::maybeAddBdusPrefix($this->db, $this->log);
            \DB\System\Migrate::run($this->db, $this->log);
        }

        (new Dispatcher($this->db, $this->log))
            ->dispatch($_GET, $_POST, $_REQUEST);
    }

    private function setupLogger(): void
    {
        $this->log = new Logger('bdus');
        $log_file  = MAIN_DIR . 'logs/error.log';

        try {
            if ($this->db && !DEBUG_ON) {
                $this->log->pushHandler(new LogDBHandler($this->db));
                $this->db->setLog($this->log);
            } else {
                $this->log->pushHandler(new StreamHandler($log_file, Logger::DEBUG));
                if ($this->db) {
                    $this->db->setLog($this->log);
                }
            }
        } catch (\Throwable $th) {
            $this->log->pushHandler(new StreamHandler($log_file, Logger::DEBUG));
            $this->log->error($th);
        }

        $handler = new ErrorHandler($this->log);
        $handler->registerErrorHandler([], false);
        $handler->registerExceptionHandler();
        $handler->registerFatalHandler();
    }
}
