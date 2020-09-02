<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Jan 12, 2013
 * 
 * @uses Exception
 * @uses cfg
 * @uses tr
 * @uses \Twig\Environment
 * @uses \Twig\Loader\FilesystemLoader
 * @uses \Twig\Extension\DebugExtension
 */

use DB\DBInterface;

abstract class Controller
{
	protected $get;
	protected $post;
	protected $request;
	protected $db;
	protected $log;
	protected $prefix;
	protected $cfg;

	public function __construct($get, $post, $request)
	{
		$this->get = $get;
		$this->post = $post;
		$this->request = $request;
	}

	public function setDB(DBInterface $db): void
	{
		$this->db = $db;
	}

	public function setCfg(\Config\Config $cfg): void
	{
		$this->cfg = $cfg;
	}
	
	public function setPrefix(string $prefix = null): void
	{
		$this->prefix = $prefix;
	}

	public function setLog(Monolog\Logger $log): void
	{
		$this->log = $log;
	}

	public function returnJson($data)
	{
		header("Content-type:application/json");
		echo json_encode($data);
	}
	
	
	public function compileTmpl($module, $template, $data = [])
	{
		if (!file_exists(MOD_DIR . $module . '/tmpl/' . $template . '.twig')) {
			throw new \Exception('Template not found');
		}

		$settings = unserialize(CACHE);
		
		$twig = new \Twig\Environment( new \Twig\Loader\FilesystemLoader(MOD_DIR . $module . '/tmpl'), $settings );
		if ($settings['debug']) {
			$twig->addExtension(new \Twig\Extension\DebugExtension());
		}

    	$data['uid'] = uniqid('uid');
    	$data['tr'] = new tr();

		return $twig->render("{$template}.twig", $data);
	}
	
	public function render($module, $template, $data = [])
	{
		echo $this->compileTmpl($module, $template, $data);
	}

}
