<?php

namespace Bdus;

class App
{
    protected $get;
    protected $post;
    protected $request;
    
    public function __construct(array $get, array $post, array $request)
    {
        $this->get = $get;
		$this->post = $post;
		$this->request = $request;
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
				throw new Exception("Object {$obj} does not have method {$method}");
			}
            if (get_parent_class($obj) === 'Controller') {
                $_aa = new $obj($this->get, $this->post, $this->request);
                $_aa->$method();                
            } else {
                // TODO: should die
                call_user_func_array( [$obj, $method], $param);
                // throw new Exception("Called object {$obj} *must* extend Controller. No direct access is available");
            }
            

		} catch(Throwable $e) {
			throw new Exception($e);
        }
	}
}