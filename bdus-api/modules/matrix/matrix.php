<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 16, 2012
 */

class matrix_ctrl extends Controller
{
	public function show()
	{
		$tb = $this->get['tb'];
		$where = $this->get['query'];
		
		try
		{
			$matrix = new Matrix($tb, $where, new DB());
			
			$dotText = $matrix->getDotContent();
			
			$safeDotTex = str_replace(array("'", "concentrate=true;", "\n"), array("\'", '', ''), trim($dotText));
			
			$this->render('matrix', 'matrix', array(
				'dotText' => $safeDotTex,
				'selfPath' => MOD_DIR . 'matrix/',
				'tb' => $tb,
				'query_arrived' => $where
			));
		}
		catch (myException $e)
		{
			$e->log();
			echo '<div class="alert alert-danger"> '
					. '<strong>' . tr::get('attention') . ':</strong> ' . tr::get($e->getMessage()) . '</p>'
				. '</div>';
		}
	}
	
	
	public function getStaticImage()
	{
		$tb = $this->get['tb'];
		$where = $this->get['query'];
			
		try
		{
			$dotfile = PROJ_TMP_DIR . uniqid('dot_') . '.dot';
		
			$matrix = new Matrix($tb, $where, new DB());
		
			switch($_SERVER['HTTP_HOST'])
			{
				case 'db.bradypus.net':
				case 'db3.bradypus.net':
				case 'db-dev.bradypus.net':
					$img_src = $matrix->write_dot($dotfile)->getFromWeb();
					break;
		
				default:
					$img_src = $matrix->write_dot($dotfile)->renderDotFile('png');
					break;
			}
		
			utils::response($img_src, 'success', true);
		
		}
		catch (myException $e)
		{
			$e->log();
			utils::response($e->getMessage(), 'error', true);
		}
	}
}