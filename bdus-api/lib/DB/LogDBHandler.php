<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB;

use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Handler\AbstractProcessingHandler;
use DB\DBInterface;

class LogDBHandler extends AbstractProcessingHandler
{
    private $db;
    private $logDb;
    private $initialized = false;

    public function __construct(DBInterface $db, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->db = $db;
        parent::__construct($level, $bubble);
    }

    /**
     * Log writes must not share a connection/transaction with the code being
     * logged. On Postgres, a failed query inside an explicit transaction
     * (e.g. Record\Persist) leaves that connection "aborted" — any further
     * statement on it, including this INSERT, fails too, so the very error
     * we're trying to record would otherwise be lost. A dedicated connection
     * keeps log writes independent of whatever state the main connection is in.
     */
    private function getLogDb(): DBInterface
    {
        if (!$this->logDb) {
            $this->logDb = new \DB\DB($this->db->getApp());
        }
        return $this->logDb;
    }

    protected function write(LogRecord $record): void
    {
        try {
            $sys_mng = new \DB\System\Manage($this->getLogDb());

            if (!$this->initialized) {
                $sys_mng->createTable('bdus_log');
                $this->initialized = true;
            }

            $sys_mng->addRow('bdus_log', [
                'channel' => $record->channel,
                'level' => $record->level->value,
                'message' => $record->formatted,
                'time' => $record->datetime->format('U')
            ]);
        } catch (\Throwable $th) {
            // Almost silently die....
            error_log("Cannot start System Manager: " . $th->getMessage());
            error_log(json_encode($th));
        }
    }
}
