<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDyUS. Communicating Cultural Heritage, http://bradypus.net 2007-2013 
 * @license			All rights reserved
 * @since				Sep 1, 2013
 */

class sync_ctrl extends Controller
{
	
	public function getStatus()
	{
		//$this->get['tmpfile']
		try
		{
			$sync = new Sync(cfg::main('ftp_string'), 'fromServer');
			$response = $sync->getOnlineStatus();
			utils::response('ok_get_status', 'success', false, $response);
		}
		catch (myException $e)
		{
			$e->log();
			utils::response('error_status_changed', 'error');
			
		}
	}
	
	public function toggleStatus()
	{
		//$this->get['tmpfile']
		try
		{
			$sync = new Sync(cfg::main('ftp_string'), 'fromServer');
			$response = $sync->toogleOnlineStatus($this->get['tmpfile']);
			utils::response('ok_status_changed', 'success', false, $response);
		}
		catch (myException $e)
		{
			$e->log();
			utils::response('error_status_changed', 'error');
		}
	}

	public function mainUI()
	{
		if (!utils::canUser('super_admin'))
		{
			echo '<div class="alert alert-danger"><i class="glyphicon glyphicon-exclamation-sign"></i> ' . tr::get('not_enough_privilege') . '</div>';
			return;
		}
		
		if (!cfg::main('ftp_string') || cfg::main('ftp_string') == '')
		{
			echo '<div class="alert alert-danger"><i class="glyphicon glyphicon-exclamation-sign"></i> ' . tr::get('ftp_not_available') . '</div>';
			return;
		}
		
		$this->render('sync', 'sync_main');
	}
	
	
	public function getRemoteList()
	{
		if (!cfg::main('ftp_string') || cfg::main('ftp_string') == '')
		{
			echo '<div class="alert alert-danger"><i class="glyphicon glyphicon-exclamation-sign"></i> ' . tr::get('ftp_not_available') . '</div>';
			return;
		}
		
		try
		{
			$sync = new Sync(cfg::main('ftp_string'), $this->get['direction']);
			$list = $sync->getChangeList();

			$this->render('sync', 'previewList', array(
				'files'=> $list,
				'direction' => $sync->getDirection(),
				'session' => $sync->getSession()
			));
		}
		catch (myException $e)
		{
			$e->log();
			echo '<div class="alert alert-danger"><i class="glyphicon glyphicon-exclamation-sign"></i> ' . tr::get('sync_error') . '</div>';
		}
			
	}
	
	
	public function sync()
	{
		try
		{
			if (!cfg::main('ftp_string') || cfg::main('ftp_string') == '')
			{
				echo '<div class="alert alert-danger"><i class="glyphicon glyphicon-exclamation-sign"></i> ' . tr::get('ftp_not_available') . '</div>';
				return;
			}
			$sync = new Sync(cfg::main('ftp_string'), $this->get['direction']);

			$sync->processFile($this->get['file']);
		}
		catch (myException $e)
		{
			$e->log();
			echo 'error';
		}
	}
	
}

?>
