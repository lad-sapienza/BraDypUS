<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;
use Monolog\ErrorHandler;

use DB\LogDBHandler;
use DB\DB;

use Config\Config;
use Adbar\Dot;

class App
{	
    // Array of GET parameters
    protected $get;
    // Array of POST parameters
    protected $post;
    // Array of REQUEST parameters
    protected $request;
    // Application prefix
    protected $prefix;
    // Application name
    protected $app;
    // Database object
    protected $db;
    // Log object
    protected $log;
    // true if debug is on
    protected $debug;
    
    
    public function __construct(array $get, array $post, array $request)
    {
        /**
        * Set $this->get to be injected in Controller
        */
        $this->get = $get;
        /**
        * Set $this->post to be injected in Controller
        */
        $this->post = $post;
        /**
        * Set $this->request to be injected in Controller
        */
        $this->request = $request;
        
    }

    public function start(): void
    {
        /**
         * Initialize Database object, if $this->app is defined
        */
        if ($this->app) {
            $this->db = new DB($this->app);
        }

        /**
         * Sets $this->log and initializes Log object
         */
        $this->setupLogger();

        $this->route();

    }

    /**
     * Set $this->app to be injected in Controller
     *
     * @param string $app
     * @return void
     */
    public function setApp( string $app ): void
    {
        $this->app = $app;
    }

    /**
     * Set $this->prefix to be injected in Controller
     *
     * @param string $prefix
     * @return void
     */
    public function setPrefix( string $prefix ): void
    {
        $this->prefix = $prefix;
    }

    /**
     * Set the debug-check variable
     *
     * @param boolean $debug
     * @return void
     */
    public function setDebug( bool $debug = false ): void
    {
        $this->debug = $debug;
    }
    
    /**
    * Initializes Log object and sets handler, depending on environment
    * @return void
    */
    private function setupLogger() : void
    {
        try {
            /**
            * Initialize logger
            */
            $this->log = new Logger('bdus');
            
            $log_file = __DIR__ . '/../../logs/error.log';
            
            /**
            * Errors are written in the database if database object is set and 
            * environment is not in debug mode
            * Otherwise, errors are written in the file
            */
            if ($this->db && !$this->debug ) {
                $this->log->pushHandler( new LogDBHandler($this->db, $this->prefix) );
                $this->db->setLog($this->log);
            } else {
                $this->log->pushHandler(new StreamHandler($log_file, Logger::DEBUG));
                $this->log->pushHandler(new FirePHPHandler());
            }
        } catch (\Throwable $th) {
            /**
            * In case of errors, logs are logged in file
            */
            $this->log->pushHandler(new StreamHandler($log_file, Logger::DEBUG));
            $this->log->error($th);
        }
        $handler = new ErrorHandler($this->log);
        $handler->registerErrorHandler([], false);
        $handler->registerExceptionHandler();
        $handler->registerFatalHandler();
    }
    
    private function route()
    {	
        // Set object
        $obj = $this->get['obj'] ?? 'home_ctrl';
        
        // Set method
        $method = $this->get['method'] ?? 'showAll';
        
        try {
            
            /**
            * Check if method exists in object
            */
            if (!method_exists($obj, $method)){
                throw new \Exception("Object {$obj} does not have method {$method}");
            }
            
            /**
            * Check if object extends Controller
            */
            if (get_parent_class($obj) !== 'Controller') {
                throw new \Exception("Called object {$obj} *must* extend Controller. No direct access is available");
            }
            
            /**
            * Initializes object
            */
            $_aa = new $obj($this->get, $this->post, $this->request);
            
            /**
             * Injects DB object, if available, to object
             */
            if ($this->db) {
                $_aa->setDB($this->db);
            }

            /**
             * Injects debug variable
             */
            $_aa->setDebug($this->debug);
            
            /**
            * Injects Log to object
            */
            $_aa->setLog($this->log);
            
            /**
            * Injects Prefix to object
            */
            $_aa->setPrefix($this->prefix);
            
            /**
            * Initializes Config and injects it to object if $this->app is available
            */
            if ($this->app) {
                $dot = new Dot();
                $config = new Config($dot, __DIR__ . '/../../projects/' . $this->app . '/cfg/', $this->prefix);
                $_aa->setCfg($config);
            }
            
            /**
            * Load locales
            */
            \tr::load_file($config ? $config->get('main.lang') : null);
            
            /**
            * Run finally the method
            */
            $_aa->$method();
            
        } catch(\Throwable $e) {
            /**
            * Catch and log errors
            */
            $this->log->error($e);
            echo "A blocking error occurred. More information are filed in the log file";
        }
    }
}