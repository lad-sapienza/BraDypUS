<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
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
		try {
			if ( !$this->request['dest_table'] || !$this->request['dest_id'] ) {
				throw new \Exception('file_data_missing');
			}

			$this->request['dont_echo'] = true;

			$result = $this->upload();

			if (!$result['success']) {
				throw new \Exception('error_uploading_file');
			}

			$record = new Record($this->prefix . 'files', false, $this->db);

			$record->setCore([
				$this->prefix . 'files' => [
					'ext' => $result['ext'],
					'filename' => $result['filename']
				]
			]);
			$pers = $record->persist();

			if (!$pers) {
				throw new \Exception('error_saving_file');
			}

			$link = $record->addUserLink($this->request['dest_table'], $this->request['dest_id']);

			if (!$link) {
				$record->delete();
				throw new \Exception('error_adding_link');
			}

			$result['status'] = 'success';
			$result['text'] = tr::get('file_uploaded_and_attached');

			echo json_encode($result);

		} catch (\Exception $e) {
			$this->log->error($e);

			echo json_encode([
				'status'=>'error',
				'text' => tr::get($e->getMessage())
			]);
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

			$maxImageSize = $this->cfg->get('main.maxImageSize') ?: 1500;

			images::resizeIfImg($result['uploadDir'] . $result['filename'] . '.' . $result['ext'], $maxImageSize);

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

		if (is_array($data['filegallery'])) {

			foreach($data['filegallery'] as $sort => $id) {
				$sql[] = [
					'UPDATE ' . $this->prefix .'userlinks SET sort = ? WHERE id = ?',
					[$sort, $id]
				];
			}

			$this->db->beginTransaction();

			try {
				foreach ($sql as $s) {
					$this->db->query($s[0], $s[1]);
				}

				$this->db->commit();

				$resp = array('status' => 'success', 'text'=> tr::get('ok_file_sorting_update'));
			
			} catch (\Exception $e) {
				$this->db->rollBack();
				$resp = array('status' => 'error', 'text'=> tr::get('error_file_sorting_update'));
			}
		} else {
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
		$record = new Record($this->request['tb'], $this->request['id'], $this->db);

		$this->render('file', 'gallery', [
			'title' => tr::get('file_gallery', [$this->cfg->get("tables.{$this->request['tb']}.label") . ', id. ' . $this->request['id']]),
			'can_edit' => utils::canUser('edit'),
			'all_files' => $record->getFullFiles(),
			'images' => new images(),
			'prefix' => $this->prefix,
			'path' => PROJ_DIR . 'files'
		]);
	}
}
