<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			See file LICENSE distributed with this code
 * @since			Aug 18, 2012
 */

class chart_ctrl extends Controller
{
	/**
	 * 
	 * @param string $this->get['tb']
	 * @param string $this->get['query']
	 */
	public function showBuilder()
	{
		$this->render('chart', 'builder', array(
				'tb' => $this->get['tb'],
				'query' => $this->get['query'],
				'fields' => cfg::fldEl($this->get['tb'], 'all', 'label'),
				'tr' => new tr(),
				'uid' =>uniqid('chart_builder')
		));
	}
	 
	/**
	 * 
	 * @param string $this->get['tb']
	 * @param string $this->get['remove']
	 */
	public function showRow()
	{
		$this->render('chart', 'bar', array(
				'remove' => $this->get['remove'],
				'uid' =>uniqid('br'),
				'flds' => cfg::fldEl($this->get['tb'], 'all', 'label'),
				'tr' => new tr()
		));
	}
	
	/**
	 * 
	 * @param array $this->post
	 */
	public function processdata()
	{
		$post = $this->post;
		
		if (!empty($post['series']))
		{
			$group_by = ' GROUP BY `' . $post['series'] . '` ';
		}
		
		foreach ($post['bar_fld'] as $bar_id=>$bar_arr)
		{
			$bar[] = ' ' . $post['bar_function'][$bar_id] . '(`' . implode('`) + ' . $post['bar_function'][$bar_id] . '(`', $bar_arr) . '`) '
				. ( $post['bar_name'][$bar_id] ? ' AS `' . $post['bar_name'][$bar_id] . '`' : '');
		}
		
		$sql = 'SELECT ' .
			( $post['series'] ? ' `' . $post['series'] . '` AS `series_name`, ' : '')  .
			implode(', ', $bar) .
			' FROM `' . $post['tb'] . '` WHERE ' .
			($post['query'] ? ' ' . base64_decode($post['query']). ' ' : '' . ' 1 ');
		
		if ($group_by && preg_match('/ORDER BY/', $sql))
		{
			$sql = preg_replace('/(.+)ORDER BY(.+)/', '$1 ' . $group_by . ' ORDER BY $2', $sql);
		}
		else if($group_by)
		{
			$sql .= ' ' . $group_by;
		}

		$ch = new Charts(new DB());
		
		$ch->formatResult(false, $sql);
		
		$this->render('chart', 'display', array(
				'uid'	=> uniqid('chart'),
				'tr'	=> new tr(),
				'data'	=> $ch->getData()
		));
		
	}
	
	/**
	 * 
	 * @param array $this->post
	 * @throws myException
	 */
	public function saveAs()
	{
		$post = $this->post;
		try
		{
			if (!$post['query_text'])
			{
				throw new myException('No query text to save!');
			}
			if (!$post['name'])
			{
				$post['name'] = uniqid('chart_');
			}
			
			$chart = new Charts(new DB());
			
			if ($chart->save($post['name'], $post['query_text']))
			{
				utils::response('ok_save_chart');
			}
			else
			{
				throw myException('Save chart query returned false');
			}
		}
		catch(myException $e)
		{
			$e->log();
			utils::response('error_save_chart', 'error');
		}
	}
	
	/**
	 * 
	 * @param int $this->get['id']
	 */
	public function erase()
	{
		$chart = new Charts(new DB());
		
		if ($chart->erase($this->get['id']))
		{
			utils::response('ok_chart_erase');
		}
		else
		{
			utils::response('error_chart_erase', 'error');
		}
	}
	
	/**
	 * 
	 * @param int $this->get['id']
	 */
	public function display_chart()
	{
		$chart = new Charts(new DB());
		
		$chart->formatResult($this->get['id']);
		
		$this->render('chart', 'display', array(
				'uid'	=> uniqid('chart'),
				'tr'	=> new tr(),
				'data'	=> $chart->getData()
				));
	}
	
	
	public function show_all()
	{
		$charts = new Charts(new DB());
		
		$this->render('chart', 'list', array(
				'all_charts' => $charts->getCharts(),
				'can_admin' => utils::canUser('admin'),
				'tr' => new tr()
		));
		
	}
	
	/**
	 * 
	 * @param int $this->get['id']
	 */
	public function edit_form()
	{
		$chart = new Charts(new DB());
		
		$mych = $chart->getCharts($this->get['id']);
		
		$mych = $mych[0];
		
		$this->render('chart', 'edit', array(
				'uid'	=> uniqid('editchart'),
				'tr'	=> new tr(),
				'chart'	=> $mych
				));
	}
	
	/**
	 * 
	 * @param array $this->post
	 * @throws myException
	 */
	public function update()
	{
		try
		{
			$chart = new Charts(new DB());
			
			if ($chart->update($this->post['id'], $this->post['name'], $this->post['text']))
			{
				utils::response('ok_update_chart');
			}
			else
			{
				throw new myException('Update query returned false');
			}
		}
		catch (myException $e)
		{
			$e->log();
			utils::response('error_update_chart', 'error');
		}
	}

}