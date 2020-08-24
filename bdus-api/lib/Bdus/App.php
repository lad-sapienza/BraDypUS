<?php

namespace Bdus;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use DB\LogDBHandler;


class App
{
    protected $get;
    protected $post;
	protected $request;
	protected $db;
	protected $log;

    
    public function __construct(array $get, array $post, array $request)
    {
        $this->get = $get;
		$this->post = $post;
		$this->request = $request;
		$this->log = new Logger('bdus');

		try {
			$this->db = new \DB();
			$this->log->pushHandler( new LogDBHandler($this->db, PREFIX) );
		} catch (\Throwable $th) {
			$this->log->pushHandler(new StreamHandler(__DIR__ . '/../../logs/error.log', Logger::DEBUG));
		}
    }

    public function route()
	{	
		// Set object
		$obj = $this->get['obj'] ?: 'home_ctrl';
		
		// Set method
        $method = $this->get['method'] ?: 'showAll';

		try {

			if (!method_exists($obj, $method)){
				throw new \Exception("Object {$obj} does not have method {$method}");
			}
            if (get_parent_class($obj) === 'Controller') {
				$_aa = new $obj($this->get, $this->post, $this->request);
                if ($this->db) {
                    $_aa->setDB($this->db);
                }
				$_aa->setLog($this->log);
                $_aa->$method();
            } else {
                throw new \Exception("Called object {$obj} *must* extend Controller. No direct access is available");
            }
		} catch(\Throwable $e) {
			$this->log->info($e);
			throw new \Exception($e);
        }
	}
}