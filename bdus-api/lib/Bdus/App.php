<?php

namespace Bdus;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;
use Monolog\ErrorHandler;

use DB\LogDBHandler;
use DB\DB;

use Config\Config;
use Adbar\Dot;

use Bdus\CompressAssets;

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

			$log_file = __DIR__ . '/../../logs/error.log';

			if (\defined('APP')){
				$this->db = new DB(APP);

				// On debug only file and firePHP are available
				if (\defined('DEBUG_ON') && \DEBUG_ON === true) {
					$this->log->pushHandler(new StreamHandler($log_file, Logger::DEBUG));
					$this->log->pushHandler(new FirePHPHandler());
				} else {
					$this->log->pushHandler( new LogDBHandler($this->db, $this->prefix) );
				}
				$this->db->setLog($this->log);
			}
		} catch (\Throwable $th) {
			$this->log->pushHandler(new StreamHandler($log_file, Logger::DEBUG));
			$this->log->error($th);
		}
		$handler = new ErrorHandler($this->log);
		$handler->registerErrorHandler([], false);
		$handler->registerExceptionHandler();
		$handler->registerFatalHandler();


		if ($get['mini'] === '1') {

			CompressAssets::All($js_compress_libs = [
				'php2js.js',
				'jquery-sortable.js',
				'bootstrap-datepicker.js',
				'jquery.dataTables.js',
				'datatables-bootstrap.js',
				'jquery.keyboard.js',
				'utils.js',
				'jquery.fineuploader-3.4.0.js',
				'core.js',
				'api.js',
				'layout.js',
				'formControls.js',
				'select2.full.js',
				'enhanceForm.js',
				'jquery.checklabel.js',
				'jquery.printElement.js',
				'jquery.jqplot.js',
				'jqplot.barRenderer.min.js',
				'jqplot.categoryAxisRenderer.min.js',
				'jqplot.pointLabels.js',
				'export-jqplot-to-png.js',
				'jquery.insertAtCaret.js',
				'bootstrap-slider.js',
				'hashActions.js',
			], $this->log);

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
                if (defined('APP')) {
					$dot = new Dot();
					$config = new Config($dot, __DIR__ . '/../../projects/' . APP . '/cfg/', $this->prefix);
					$_aa->setCfg($config);
				}
				\tr::load_file($config ? $config->get('main.lang') : null);
                $_aa->$method();
            } else {
                throw new \Exception("Called object {$obj} *must* extend Controller. No direct access is available");
            }
		} catch(\Throwable $e) {
			$this->log->error($e);
			echo "A blocking error occurred. More information are filed in the log file";
        }
	}
}