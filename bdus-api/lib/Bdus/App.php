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

        // PARAMS SHOULD DIE!!!
        if (isset($this->request['param']) && is_string($this->request['param'])) {
			// get or post single (string) param
			$param = [ $this->request ];

		} else if (!isset($this->request['param']) && empty($this->post)) {
			// no post and no no post/get param
			$param = [];

		} else if (!isset($this->request['param']) && !empty($this->post)) {
			// no post/get param, but post data
			$param = [ $this->post ];

		} else if (isset($this->request['param']) && !empty($this->post)) {
			$param = array( array_merge($this->post, $this->request['param']));
		} else {
			$param = $this->request['param'];
		}
		
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
				// TODO: should die
				$this->log->error("Called deprecated method", ["obj" => $obj, "method" => $method, "param" => $param]);
                call_user_func_array( [$obj, $method], $param);
                // throw new Exception("Called object {$obj} *must* extend Controller. No direct access is available");
            }
		} catch(\Throwable $e) {
			$this->log->info($e);
			throw new \Exception($e);
        }
	}
}