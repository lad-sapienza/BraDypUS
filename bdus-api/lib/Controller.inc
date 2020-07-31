<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Jan 12, 2013
 */

class Controller
{
	protected $get, $post, $request;

	public function __construct($get = false, $post = false, $request = false)
	{
		$this->get = $get ? $get : $_GET;
		$this->post = $post ? $post : $_POST;
		$this->request = $request ? $request : $_REQUEST;
	}

	public function json($data)
	{
		header("Content-type:application/json");
		echo json_encode($data);
	}

	public function render($module, $template, $data = [])
	{
		if (file_exists(MOD_DIR . $module . '/tmpl/' . $template . '.html')) {
			
			$ext = '.html';

		} else if (file_exists(MOD_DIR . $module . '/tmpl/' . $template . '.twig')) {
			
			$ext = '.twig';

		} else {

			throw new myException('Template not found');

		}

		$settings = unserialize(CACHE);
		$settings['autoescape'] = false;

		$twig = new \Twig\Environment( new \Twig\Loader\FilesystemLoader(MOD_DIR . $module . '/tmpl'), $settings );
		if ($settings['debug']) {
			$twig->addExtension(new \Twig\Extension\DebugExtension());
		}

    	$data['uid'] = uniqid('uid');
    	$data['tr'] = new tr();

		echo $twig->render($template . $ext, $data);
	}

	public function route()
	{
		if ($this->request['obj']) {

			$obj = $this->request['obj'];
			$method = $this->request['method'];

			try {

				if($this->request['force_array']) {

					unset($this->request['obj']);
					unset($this->request['method']);
					unset($this->request['force_array']);
					$param = [ $this->request ];

				} else if ($this->request['param'] AND is_string($this->request['param'])) {
					// get or post single (string) param
					$param = [ $this->request ];

				} else if (!$this->request['param'] AND empty($this->post)) {
					// no post and no no post/get param
					$param = [];

				} else if (!$this->request['param'] AND !empty($this->post)) {
					// no post/get param, but post data
					$param = [ $this->post ];

				} else if ($this->request['param'] && !empty($this->post)) {
					$param = array( array_merge($this->post, $this->request['param']));
				} else {
					$param = $this->request['param'];
				}

				if (get_parent_class($obj) === 'Controller') {
					$_aa = new $obj($this->get, $this->post, $this->request);
					$_aa->$method();
				} else {
					call_user_func_array( [$obj, $method], $param);
				}

			} catch(myException $e) {
				$e->log();
				throw new myException(sprintf(tr::get('error_in_method'), $this->request['obj'] . '::' . $this->request['method']));
			}

		} else {
			$home = new home($this->get, $this->post, $this->request);
			$home->showAll();
		}
	}
}
