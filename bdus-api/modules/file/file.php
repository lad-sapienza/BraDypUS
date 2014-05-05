<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2013
 * @license			See file LICENSE distributed with this code
 * @since			Jan 8, 2013
 */

class file_ctrl extends Controller
{
	public function upload_args($upload_dir = false, $dont_echo = false)
	{
		$this->request['upload_dir'] = $upload_dir;
		$this->request['dont_echo'] = $dont_echo;
		
		return $this->upload();
	}
	
	/**
	 *
	 * @param string $this->request['upload_dir']
	 * @param string $this->request['dest_table']
	 * @param string $this->request['dest_id']
	 * @return string|array
	 */
	public function uploadLink()
	{
		try
		{
			if (
					!$this->request['dest_table'] ||
					!$this->request['dest_id']
					)
			{
				throw new myException('file_data_missing');
			}
			
			$this->request['dont_echo'] = true;
			
			$result = $this->upload();
			
			if (!$result['success'])
			{
				throw new myException('error_uploading_file');
			}
			
			$record = new Record(PREFIX . '__files', false, new DB());
			
			$record->setCore(
					array(
							PREFIX . '__files' => array(
									'ext' => $result['ext'],
									'filename' => $result['filename']
									)
							)
					);
			$pers = $record->persist();
			
			if (!$pers)
			{
				throw new myException('error_saving_file');
			}
			
			$link = $record->addUserLink($this->request['dest_table'], $this->request['dest_id']);
			
			if (!$link)
			{
				$record->delete();
				throw new myException('error_adding_link');
			}
			
			$result['status'] = 'success';
			$result['text'] = tr::get('file_uploaded_and_attached');
			
			echo json_encode($result);
			
		}
		catch (myException $e)
		{
			$e->log();
			
			echo json_encode(
					array(
							'status'=>'error',
							'text' => tr::get($e->getMessage())
							)
					);
		}
	}
	
	/**
	 * 
	 * @param string $this->request['upload_dir']
	 * @param string $this->request['dont_echo']
	 * @return string|array
	 */
	public function upload()
	{
		$upload_dir = $this->request['upload_dir'] ?: PROJ_TMP_DIR;
		
		$uploader = new qqFileUploader();
		
		$result = $uploader->handleUpload($upload_dir);
		
		if ($result['success'])
		{
		
			$result['filename'] = pathinfo($uploader->getUploadName(), PATHINFO_FILENAME);
		
			$result['ext'] = pathinfo($uploader->getUploadName(), PATHINFO_EXTENSION);
			
			$result['uploadDir'] = $upload_dir;
			
			$result['thumbnail'] = images::getThumbHtml(array('id' => $result['filename'], 'ext' => $result['ext']), $upload_dir);
		}
		
		if ($this->request['dont_echo'])
		{
			return $result;
		}
		else
		{
			echo json_encode($result);
		}
	}
	
	
	/**
	 * 
	 * @param array $this->post
	 */
	public function sort()
	{
		$data = $this->post;
		
		if (is_array($data['filegallery']))
		{
			foreach($data['filegallery'] as $sort => $id)
			{
				$sql[] = 'UPDATE `' . PREFIX .'__userlinks` SET `sort` = ' . $sort . ' WHERE `id` = ' . $id;
			}
			
			$db = new DB();
			
			$db->beginTransaction();
			
			try
			{
				foreach ($sql as $s)
				{
					$db->query($s);
				}
				
				$db->commit();
				
				$resp = array('status' => 'success', 'text'=> tr::get('ok_file_sorting_update'));
			}
			catch (myException $e)
			{
				$db->rollBack();
				$resp = array('status' => 'error', 'text'=> tr::get('error_file_sorting_update'));
			}
		}
		else
		{
			$resp = array('status' => 'error', 'text'=> tr::get('error_file_sorting_update'));
		}
		
		echo json_encode($resp);
	}
	
	/**
	 * 
	 * @param string $this->request['tb']
	 * @param int $this->request['id']
	 */
	public function gallery()
	{
		$record = new Record($this->request['tb'], $this->request['id'], new DB);
				
		$this->render('file', 'gallery', array(
				'title' => tr::sget('file_gallery', cfg::tbEl($this->request['tb'], 'label') . ', id. ' . $this->request['id']),
				'can_edit' => utils::canUser('edit'),
				'all_files' => $record->getFullFiles(),
				'images' => new images(),
				'prefix' => PREFIX,
				'path' => PROJ_FILES_DIR
		));
	}
}