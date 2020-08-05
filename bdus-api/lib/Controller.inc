<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Jan 12, 2013
 */

class Controller
{
	protected $get;
	protected $post;
	protected $request;

	public function __construct($get, $post, $request)
	{
		$this->get = $get;
		$this->post = $post;
		$this->request = $request;
	}

	public function returnJson($data)
	{
		header("Content-type:application/json");
		echo json_encode($data);
	}

	public function render($module, $template, $data = [])
	{
		if (!file_exists(MOD_DIR . $module . '/tmpl/' . $template . '.twig')) {
			throw new myException('Template not found');
		}

		$settings = unserialize(CACHE);
		
		$twig = new \Twig\Environment( new \Twig\Loader\FilesystemLoader(MOD_DIR . $module . '/tmpl'), $settings );
		if ($settings['debug']) {
			$twig->addExtension(new \Twig\Extension\DebugExtension());
		}

    	$data['uid'] = uniqid('uid');
    	$data['tr'] = new tr();

		echo $twig->render("{$template}.twig", $data);
	}

	public function route()
	{	
		// Set object
		$obj = $this->request['obj'] ?: 'home_ctrl';
		
		// Set method
		$method = $this->request['method'] ?: 'showAll';

		// Set param
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
				throw new myException("Object {$obj} does not have method {$method}");
			}
			if (get_parent_class($obj) === 'Controller') {
				$_aa = new $obj($this->get, $this->post, $this->request);
				$_aa->$method();
			} else {
				call_user_func_array( [$obj, $method], $param);
			}

		} catch(Throwable $e) {
			throw new myException($e);
		}
	}
}
