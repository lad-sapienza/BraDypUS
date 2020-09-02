<?php
namespace DB;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use \DB\DB\DBInterface;

class LogDBHandler extends AbstractProcessingHandler
{
    private $db;
    private $prefix;
    private $initialized = false;

    public function __construct(DBInterface $db, string $prefix, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        try {
            $sys_mng = new \DB\System\Manage($this->db, $this->prefix);
            if (!$this->initialized) {
                $sys_mng->createTable('log');
                $this->initialized = true;
            }

            $sys_mng->addRow('log', [
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