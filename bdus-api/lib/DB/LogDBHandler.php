<?php
namespace DB;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

class LogDBHandler extends AbstractProcessingHandler
{
    private $initialized = false;
    private $db;
    private $prefix;

    public function __construct(\DB $db, string $prefix = null, $level = Logger::DEBUG, bool $bubble = true)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        if (!$this->initialized) {
            $this->initialize();
        }

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

    private function initialize()
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . $this->prefix . 'log '
            .'(channel TEXT, level INTEGER, message TEXT, time INTEGER UNSIGNED)'
        );
        
        $this->initialized = true;
    }
}