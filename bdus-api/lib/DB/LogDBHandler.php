<?php
namespace DB;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

class LogDBHandler extends AbstractProcessingHandler
{
    private $db;
    private $prefix;

    public function __construct(\DB\DB\DBInterface $db, string $prefix = null, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        $this->db->query(
            'INSERT INTO ' . $this->prefix . 'log (channel, level, message, time) VALUES (:channel, :level, :message, :time)',
            [
                'channel' => $record['channel'],
                'level' => $record['level'],
                'message' => $record['formatted'],
                'time' => $record['datetime']->format('U'),
            ],
            'boolean'
        );
    }
}