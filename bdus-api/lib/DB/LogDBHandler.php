<?php
namespace DB;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

class LogDBHandler extends AbstractProcessingHandler
{
    private $db;

    public function __construct(\DB\DB\DBInterface $db, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->db = $db;
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        $this->db->logError( 
            $record['channel'], 
            $record['level'],
            $record['formatted'],
            $record['datetime']->format('U')
        );
    }
}