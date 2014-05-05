<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDyUS. Communicating Cultural Heritage, http://bradypus.net 2007-2013 
 * @license			See file LICENSE distributed with this code
 * @since				Sep 5, 2013
 * @uses FTPClien Description
 */

use FtpPhp\FtpClient;
use FtpPhp\FtpException;


class Sync
{
	/**
	 *
	 * @var string Sync Direction: fromServer|toServer
	 */
	private $direction;
	
	/**
	 *
	 * @var object, instance of FTPClient
	 */
	private $ftp;
	
	/**
	 *
	 * @var array Array of changed files to be transfered
	 */
	private $changeList;
	
	
	/**
	 * Starts object and starts FTP object
	 * @param string $ftp_string FTP connection string: ftp://username:password@server
	 * @param string $direction Sync direction: fromServer|toServer
	 */
	public function __construct($ftp_string, $direction)
	{
		$this->ftp =  new FtpClient($ftp_string);
		
		if ($direction != 'fromServer' && $direction != 'toServer')
		{
			throw new myException('Unknown direction: [' . $direction . ']. Only toServer or fromServer allowed');
		}
		$this->direction = $direction;
	}
	
	
	/**
	 * Changes online status, available in an already downloaded tmp file, toggles the status on/frozen
	 * and uploads the file in the remote server.
	 * Returns array with tmp file where remote app_data.json is stored and remote application status
	 * @param string $tmpfile	full path to tmp file
	 * @return array
	 * @throws myException
	 */
	public function toogleOnlineStatus($tmpfile)
	{
		$data = json_decode(file_get_contents($tmpfile), true);
		
		if ($data['status'] == 'on')
		{
			$data['status'] = 'frozen';
		}
		else if ($data['status'] == 'frozen')
		{
			$data['status'] = 'on';
		}
		
		utils::write_formatted_json($tmpfile, $data);
		
		try
		{
			$this->ftp->put('cfg/app_data.json', $tmpfile, FtpClient::BINARY);
			
			return array('file' => $tmpfile, 'app_status' => $data['status']);
		}
		catch (FtpException $e)
		{
			throw new myException($e);
		}
	}
	
	/**
	 * Returns array with tmp file where remote app_data.json is stored and remote application status
	 * @param string $tmpfile full path to tmp file with remote app_data.json is located. If not provided will be donwloaded
	 * @return array
	 * @throws myException
	 */
	public function getOnlineStatus($tmpfile = false)
	{
		if ($tmpfile && file_exists($tmpfile))
		{
			$data = json_decode(file_get_contents($tmpfile), true);
			
			return array('file' => $tmpfile, 'app_status' => $data['status']);
		}
		else
		{
			try
			{
				$file = PROJ_TMP_DIR . uniqid() . '.json';
				$sync = $this->ftp->get($file, '/cfg/app_data.json', FtpClient::BINARY);
				
				return $this->getOnlineStatus($file);
			}
			catch (FtpException $e)
			{
				throw new myException($e);
			}
		}
	}
	
	
	
	/**
	 * Returns current direction
	 * @return string
	 */
	public function getDirection()
	{
		return $this->direction;
	}
	
	
	/**
	 * Return list of files to copy, depending on direction.
	 * @return array
	 */
	public function getChangeList()
	{
		return $this->getData();
	}
	
	
	/**
	 * Return length of the list of files to be copied
	 * @return int
	 */
	public function getListLength()
	{
		return count($this->changeList);
	}
	
	
	/**
	 * Processes (copies) a single file, depending on direction. If path (folders) does not exists, it will be creted
	 * @param type $file
	 * @return type
	 * @throws myException
	 */
	public function processFile($file)
	{
		// 1. Download FROM server. $file does not contain project path
		if ($this->direction == 'fromServer')
		{
			// Check if local dir exists, if not try to create it
			if (!is_dir(PROJ_DIR . dirname($file)))
			{
				if (!mkdir(PROJ_DIR . dirname($file), 0777, true))
				{
					throw new myException('Can not create local directory:' . PROJ_DIR . dirname($file));
				}
			}
			return $this->ftp->get(PROJ_DIR . '/' . $file, $file, FtpClient::BINARY);
		}
		
		// 2. Upload TO server. $file contains project path
		else if ($this->direction == 'toServer')
		{
			// Check if remore dir exists, if not try to create it
			if (!$this->ftp->isDir(dirname(str_replace(PROJ_DIR, null, $file))))
			{
				if (!$this->ftp->mkDirRecursive(dirname(str_replace(PROJ_DIR, null, $file))))
				{
					throw new myException('Can not create remote directory: ' . dirname(str_replace(PROJ_DIR, null, $file)));
				}
			}
			return $this->ftp->put(str_replace(PROJ_DIR, null, $file), $file, FtpClient::BINARY);
		}
	}

	
	/**
	 * Returns array of files to be transferred. If array is not ready, it will be created, loaded from a json file, or created by comparing sources
	 * @return array
	 * @throws myException
	 */
	private function getData()
	{
		set_time_limit(0);
		// If list is not available, it will be created
		if (!$this->changeList)
		{
			try{
				
				if ($this->direction == 'fromServer')
				{
					$this->recursiveServerFileList();
				}
				else if ($this->direction == 'toServer')
				{
					$this->recursiveLocalFileList();
				}
				else
				{
					throw new myException('Unknown sync direction');
				}

				return $this->changeList;

			}
			catch (FtpException $e)
			{
				throw new myException($e);
			}
		}
		
		return $this->changeList;
	}
	
	
	/**
	 * Sets, recursively, array of $changedFiles getting list from local server. All files will be listed. Only files inside the FILES dir will be checked
	 * @param string $dir directory to look in
	 * @return
	 */
	private function recursiveLocalFileList($dir = PROJ_DIR)
	{
		$dir_content = utils::dirContent($dir);
		
		if (!is_array($dir_content))
		{
			return;
		}
		
		foreach($dir_content as $file)
		{
			$local_path = str_replace('//', '/', $dir . '/' . $file);
			$remote_path = str_replace(PROJ_DIR, null, $local_path);
			
			if (is_dir($local_path))
			{
				$this->recursiveLocalFileList($local_path);
			}
			else
			{
				// file folder will be cheked, other files and folder will be automatically copied
				if (preg_match('/files\//', $local_path))
				{
					if (!$this->ftp->fileExists($remote_path) || filesize($local_path) != $this->ftp->size($remote_path))
					{
						$this->changeList[$local_path] = filesize($local_path);
					}
				}
				else
				{
					$this->changeList[$local_path] = filesize($local_path);
				}
			}
		}
		return;
	}
	
	
	/**
	 * Sets, recursively, array of $changedFiles getting list from remote server. All files will be listed. Only files inside the FILES dir will be checked
	 * @param string $dir directory to look in
	 * @return
	 */
	private function recursiveServerFileList($dir = '')
	{
		$dir_content = $this->ftp->nlist($dir);
		
		if (!is_array($dir_content))
		{
			return;
		}
		
		foreach ($dir_content as $file)
		{
			if (substr($file, -2) == '..' || substr($file, -1) == '.')
			{
				continue;
			}
			
			if ($this->ftp->isDir($file))
			{
				$this->recursiveServerFileList($file);
			}
			else
			{
				if (preg_match('/files\//', $file))
				{
					if (!file_exists(PROJ_DIR . $file) || $this->ftp->size($file) != @filesize(PROJ_DIR . $file))
					{
						$this->changeList[$file] = $this->ftp->size($file);
					}
				}
				else
				{
					$this->changeList[$file] = $this->ftp->size($file);
				}
			}
		}
		return;
	}
}
?>
