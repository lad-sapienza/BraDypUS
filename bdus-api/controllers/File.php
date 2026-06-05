<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

use \Intervention\Image\ImageManager;
use \Intervention\Image\Drivers\Gd\Driver;

class File extends \Bdus\Controller
{
	public function rotate(): void
	{
		try {
			$image = $this->get['image'];
			$im = new ImageManager(new Driver());
			$im->read($image)->rotate(90)->save($image);
			$this->returnJson(['status' => 'success', 'code' => 'img_rotated']);
		} catch (\Throwable $th) {
			$this->log->error($th);
			$this->returnJson(['status' => 'error', 'code' => 'img_not_rotated']);
		}
	}

	/**
	 * Returns paginated list of all files in the app.
	 *
	 * GET /api/files?page=1&per_page=25&orphans_only=1
	 *
	 * Response: { status, total, page, per_page, files: [{ id, ext, filename,
	 *   description, keywords, printable, is_image,
	 *   links: [{ tb, record_id }] }] }
	 */
	public function getFiles(): void
	{
		if (!\Auth\Authorization::can('read')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$page       = max(1, (int)($this->get['page']     ?? 1));
		$perPage    = max(5, min(100, (int)($this->get['per_page'] ?? 25)));
		$orphansOnly= !empty($this->get['orphans_only']);
		$offset     = ($page - 1) * $perPage;

		$imageExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg'];

		try {
			if ($orphansOnly) {
				$countSql  = "SELECT COUNT(*) AS cnt FROM bdus_files f
				              WHERE NOT EXISTS (SELECT 1 FROM bdus_file_links fl WHERE fl.file_id = f.id)";
				$fetchSql  = "SELECT f.id, f.ext, f.filename, f.description, f.keywords, f.printable
				              FROM bdus_files f
				              WHERE NOT EXISTS (SELECT 1 FROM bdus_file_links fl WHERE fl.file_id = f.id)
				              ORDER BY f.id DESC LIMIT ? OFFSET ?";
			} else {
				$countSql  = "SELECT COUNT(*) AS cnt FROM bdus_files";
				$fetchSql  = "SELECT id, ext, filename, description, keywords, printable
				              FROM bdus_files ORDER BY id DESC LIMIT ? OFFSET ?";
			}

			$countRow  = $this->db->query($countSql, [], 'read');
			$total     = (int)($countRow[0]['cnt'] ?? 0);

			$rows      = $this->db->query($fetchSql, [$perPage, $offset], 'read') ?: [];

			if (empty($rows)) {
				$this->returnJson([
					'status'   => 'success',
					'total'    => $total,
					'page'     => $page,
					'per_page' => $perPage,
					'files'    => [],
				]);
				return;
			}

			$ids       = array_column($rows, 'id');
			$placeholders = implode(',', array_fill(0, count($ids), '?'));
			$linkRows  = $this->db->query(
				"SELECT file_id, table_name, record_id FROM bdus_file_links WHERE file_id IN ($placeholders)",
				$ids,
				'read'
			) ?: [];

			$linkMap   = [];
			foreach ($linkRows as $lr) {
				$linkMap[(int)$lr['file_id']][] = ['tb' => $lr['table_name'], 'record_id' => (int)$lr['record_id']];
			}

			$files = [];
			foreach ($rows as $r) {
				$id      = (int)$r['id'];
				$ext     = $r['ext'] ?? '';
				$files[] = [
					'id'          => $id,
					'ext'         => $ext,
					'filename'    => $r['filename'] ?? '',
					'description' => $r['description'],
					'keywords'    => $r['keywords'],
					'printable'   => isset($r['printable']) ? (bool)$r['printable'] : null,
					'is_image'    => in_array(strtolower($ext), $imageExts, true),
					'links'       => $linkMap[$id] ?? [],
				];
			}

			$this->returnJson([
				'status'   => 'success',
				'total'    => $total,
				'page'     => $page,
				'per_page' => $perPage,
				'files'    => $files,
			]);

		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
		}
	}

	/**
	 * Updates file metadata (description, keywords, printable).
	 *
	 * PATCH /api/file/{fileId}
	 * Body: { description?, keywords?, printable? }
	 *
	 * Response: { status, code }
	 */
	public function updateFile(): void
	{
		if (!\Auth\Authorization::can('edit')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$fileId = (int)($this->get['fileId'] ?? 0);
		if (!$fileId) {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}

		$rows = $this->db->query("SELECT id FROM bdus_files WHERE id = ?", [$fileId], 'read');
		if (empty($rows)) {
			$this->returnJson(['status' => 'error', 'code' => 'record_not_found']);
			return;
		}

		$description = $this->post['description'] ?? null;
		$keywords    = $this->post['keywords']    ?? null;
		$printable   = isset($this->post['printable']) ? (int)(bool)$this->post['printable'] : null;

		$sets  = [];
		$vals  = [];
		if (array_key_exists('description', $this->post)) { $sets[] = 'description = ?'; $vals[] = $description; }
		if (array_key_exists('keywords',    $this->post)) { $sets[] = 'keywords    = ?'; $vals[] = $keywords;    }
		if (array_key_exists('printable',   $this->post)) { $sets[] = 'printable   = ?'; $vals[] = $printable;   }

		if (empty($sets)) {
			$this->returnJson(['status' => 'success', 'code' => 'ok_file_updated']);
			return;
		}

		$vals[] = $fileId;
		$ok = $this->db->query(
			"UPDATE bdus_files SET " . implode(', ', $sets) . " WHERE id = ?",
			$vals,
			'boolean'
		);

		if ($ok) {
			$this->returnJson(['status' => 'success', 'code' => 'ok_file_updated']);
		} else {
			$this->returnJson(['status' => 'error', 'code' => 'error_file_updated']);
		}
	}

	/**
	 * Replaces the physical file binary while preserving all metadata.
	 *
	 * POST /api/file/{fileId}/replace
	 * Multipart body: file=<binary>
	 *
	 * Response: { status, code, ext, filename }
	 */
	public function replaceFile(): void
	{
		if (!\Auth\Authorization::can('edit')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$fileId = (int)($this->get['fileId'] ?? 0);
		if (!$fileId) {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}

		if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
			$this->returnJson(['status' => 'error', 'code' => 'error_uploading_file']);
			return;
		}

		$rows = $this->db->query("SELECT ext FROM bdus_files WHERE id = ?", [$fileId], 'read');
		if (empty($rows)) {
			$this->returnJson(['status' => 'error', 'code' => 'record_not_found']);
			return;
		}
		$oldExt = $rows[0]['ext'];

		try {
			$original = basename($_FILES['file']['name']);
			$newExt   = strtolower(pathinfo($original, PATHINFO_EXTENSION));
			$newName  = pathinfo($original, PATHINFO_FILENAME);

			$destDir  = PROJ_DIR . 'files/';
			$newPath  = $destDir . $fileId . '.' . $newExt;

			if (!move_uploaded_file($_FILES['file']['tmp_name'], $newPath)) {
				throw new \RuntimeException('move_uploaded_file failed');
			}

			// Delete old physical file if extension changed
			if ($oldExt !== $newExt) {
				$oldPath = $destDir . $fileId . '.' . $oldExt;
				if (file_exists($oldPath)) {
					@unlink($oldPath);
				}
			}

			// Resize if configured
			$maxPx = (int) trim((string) ($this->cfg->get('main.maxImageSize') ?? 0));
			if ($maxPx > 0) {
				\Image\Resizer::maybeResize($newPath, $maxPx);
			}

			$this->db->query(
				"UPDATE bdus_files SET ext = ?, filename = ? WHERE id = ?",
				[$newExt, $newName, $fileId],
				'boolean'
			);

			$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg'];
			$this->returnJson([
				'status'   => 'success',
				'code'     => 'ok_file_replaced',
				'ext'      => $newExt,
				'filename' => $newName,
				'is_image' => in_array($newExt, $imageExts, true),
			]);

		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'error_uploading_file', 'detail' => $e->getMessage()]);
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
