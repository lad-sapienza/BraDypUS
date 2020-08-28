<?php

namespace Bdus;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\ErrorHandler;

use DB\LogDBHandler;


class App
{
    protected $get;
    protected $post;
	protected $request;
	protected $prefix;
	protected $db;
	protected $log;

    
    public function __construct(array $get, array $post, array $request)
    {
        $this->get = $get;
		$this->post = $post;
		$this->request = $request;
		if (defined('PREFIX')){
			$this->prefix = PREFIX;
		}

		try {
			$this->log = new Logger('bdus');

			if (\defined('APP')){
				$this->db = new \DB(APP);
				$this->log->pushHandler( new LogDBHandler($this->db, $this->prefix) );
				$this->db->setLog($this->log);
			}
		} catch (\Throwable $th) {
			$this->log->pushHandler(new StreamHandler(__DIR__ . '/../../logs/error.log', Logger::DEBUG));
			$this->log->error($th);
		}
		$handler = new ErrorHandler($this->log);
		$handler->registerErrorHandler([], false);
		$handler->registerExceptionHandler();
		$handler->registerFatalHandler();


		if ($get['mini']) {
			\utils::modScripts($this->log);
			\utils::emptyDir(MAIN_DIR . 'cache', false);
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
				$_aa->setPrefix($this->prefix);
                $_aa->$method();
            } else {
                throw new \Exception("Called object {$obj} *must* extend Controller. No direct access is available");
            }
		} catch(\Throwable $e) {
			$this->log->error($e);
			var_dump($e);
			echo "A fatal error occurred and the application could not be started. More information are filed in the log file";
        }
	}
}