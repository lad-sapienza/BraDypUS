<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use \Intervention\Image\ImageManager;
use \Intervention\Image\Drivers\Gd\Driver;

class file_ctrl extends Controller
{
	public function rotate(): void
	{
		try {
			$image = $this->get['image'];
			$im = new ImageManager(new Driver());
			$im->read($image)->rotate(90)->save($image);
			echo $this->response('img_rotated', 'success');
		} catch (\Throwable $th) {
			$this->log->error($th);
			echo $this->response('img_not_rotated', 'error');
		}
	}

	/**
	 * Updates the sort order of file_links for a record's file gallery.
	 *
	 * POST ?obj=file_ctrl&method=sortFiles
	 * Body: { order: [ file_link_id, ... ] }   — ordered array of file_links.id
	 *
	 * Response: { status, code }
	 */
	public function sortFiles(): void
	{
		if (!\Auth\Authorization::can('edit')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$order = $this->post['order'] ?? [];
		if (!is_array($order) || empty($order)) {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}

		$error  = false;
		foreach ($order as $sort => $fileLinkId) {
			$ok = $this->db->query(
				"UPDATE bdus_file_links SET sort = ? WHERE id = ?",
				[(int)$sort, (int)$fileLinkId],
				'boolean'
			);
			if (!$ok) {
				$error = true;
			}
		}

		if ($error) {
			$this->returnJson(['status' => 'error', 'code' => 'error_file_sorting_update']);
		} else {
			$this->returnJson(['status' => 'success', 'code' => 'ok_file_sorting_update']);
		}
	}
}
