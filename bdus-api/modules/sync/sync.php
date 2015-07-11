<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since				Sep 1, 2013
 */

class sync_ctrl extends Controller
{
	
  private function startSync($direction = 'fromServer')
  {
    try
    {
      return new Sync(cfg::main('ftp_string'), $direction);
    }
    catch (myException $e)
    {
      utils::response($e->getMessage(), 'error');
    }
  }
  
	public function getStatus()
	{
		
    $sync = $this->startSync();
    
    if (!isset($sync) || !$sync)
    {
      return;
    }
    
		try
		{
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
		$sync = $this->startSync();
    
    if (!isset($sync) || !$sync)
    {
      return;
    }
    
		try
		{
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
		
    $sync = $this->startSync($this->get['direction']);
    
    if (!isset($sync) || !$sync)
    {
      return;
    }
    
    try
		{
			$list = $sync->getChangeList();

			$this->render('sync', 'previewList', array(
				'files'=> $list,
				'direction' => $sync->getDirection()
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
    if (!cfg::main('ftp_string') || cfg::main('ftp_string') == '')
    {
      echo '<div class="alert alert-danger"><i class="glyphicon glyphicon-exclamation-sign"></i> ' . tr::get('ftp_not_available') . '</div>';
      return;
    }
      
    $sync = $this->startSync($this->get['direction']);
    
    if (!isset($sync) || !$sync)
    {
      return;
    }
    
		try
		{
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
