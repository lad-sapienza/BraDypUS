<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace DB;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use DB\DBInterface;

class LogDBHandler extends AbstractProcessingHandler
{
    private $db;
    private $initialized = false;

    public function __construct(DBInterface $db, string $prefix = '', $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->db = $db;
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        try {
            $sys_mng = new \DB\System\Manage($this->db);

            if (!$this->initialized) {
                $sys_mng->createTable('bdus_log');
                $this->initialized = true;
            }

            $sys_mng->addRow('bdus_log', [
                'channel' => $record['channel'],
                'level' => $record['level'],
                'message' => $record['formatted'],
                'time' => $record['datetime']->format('U')
            ]);
        } catch (\Throwable $th) {
            // Almost silently die....
            error_log("Cannot start System Manager: " . $th->getMessage());
            error_log(json_encode($th));
        }
    }
}
