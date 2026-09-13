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
			$im->decode($image)->rotate(90)->save($image);
			$this->returnJson(['status' => 'success', 'code' => 'img_rotated']);
		} catch (\Throwable $th) {
			$this->log->error($th);
			$this->returnJson(['status' => 'error', 'code' => 'img_not_rotated']);
		}
	}

	/**
	 * Returns paginated list of all files in the app.
	 *
	 * GET /api/files?page=1&per_page=25&orphans_only=1&search=foo
	 *
	 * `search` matches (case-insensitively, via LIKE) against filename,
	 * description and keywords; if it's a plain integer it also matches the
	 * file id exactly.
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

		$page        = max(1, (int)($this->get['page']     ?? 1));
		$perPage     = max(5, min(100, (int)($this->get['per_page'] ?? 25)));
		$orphansOnly = !empty($this->get['orphans_only']);
		$search      = trim((string)($this->get['search'] ?? ''));
		$offset      = ($page - 1) * $perPage;

		$imageExts  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'svg'];

		try {
			$conditions = [];
			$params     = [];

			if ($orphansOnly) {
				$conditions[] = 'NOT EXISTS (SELECT 1 FROM bdus_file_links fl WHERE fl.file_id = f.id)';
			}

			if ($search !== '') {
				$needle    = '%' . $search . '%';
				$searchOr  = ['f.filename LIKE ?', 'f.description LIKE ?', 'f.keywords LIKE ?'];
				$searchVal = [$needle, $needle, $needle];
				if (ctype_digit($search)) {
					$searchOr[]  = 'f.id = ?';
					$searchVal[] = (int) $search;
				}
				$conditions[] = '(' . implode(' OR ', $searchOr) . ')';
				$params = array_merge($params, $searchVal);
			}

			$whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

			$countSql = "SELECT COUNT(*) AS cnt FROM bdus_files f {$whereSql}";
			$fetchSql = "SELECT f.id, f.ext, f.filename, f.description, f.keywords, f.printable
			             FROM bdus_files f {$whereSql}
			             ORDER BY f.id DESC LIMIT ? OFFSET ?";

			$countRow  = $this->db->query($countSql, $params, 'read');
			$total     = (int)($countRow[0]['cnt'] ?? 0);

			$rows      = $this->db->query($fetchSql, [...$params, $perPage, $offset], 'read') ?: [];

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
	 * Updates file metadata (filename, description, keywords, printable).
	 *
	 * Renaming only ever touches this DB column — the physical file keeps
	 * living at `{id}.{ext}` regardless of `filename` (see uploadFile()/
	 * replaceFile()), so a rename is a pure metadata edit, no filesystem or
	 * URL implications.
	 *
	 * PATCH /api/file/{fileId}
	 * Body: { filename?, description?, keywords?, printable? }
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

		if (array_key_exists('filename', $this->post) && trim((string) $this->post['filename']) === '') {
			$this->returnJson(['status' => 'error', 'code' => 'filename_required']);
			return;
		}

		$filename    = isset($this->post['filename']) ? trim((string) $this->post['filename']) : null;
		$description = $this->post['description'] ?? null;
		$keywords    = $this->post['keywords']    ?? null;
		$printable   = isset($this->post['printable']) ? (int)(bool)$this->post['printable'] : null;

		$sets  = [];
		$vals  = [];
		if (array_key_exists('filename',    $this->post)) { $sets[] = 'filename    = ?'; $vals[] = $filename;    }
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

			// Resize / convert format if configured — may change the extension,
			// which bdus_files.ext must follow (see Record::uploadFile() for the
			// same pattern).
			$processed = \Image\Resizer::process($newPath, [
				'maxPx'   => (int) ($this->cfg->get('main.maxImageSize') ?? 0),
				'convert' => (bool) ($this->cfg->get('main.imageConvert') ?? false),
				'format'  => (string) ($this->cfg->get('main.imageFormat') ?? 'webp'),
				'quality' => (int) ($this->cfg->get('main.imageQuality') ?? 85),
				'dpi'     => (int) ($this->cfg->get('main.imageDpi') ?? 72),
			]);
			$newExt = $processed['ext'];

			$this->db->query(
				"UPDATE bdus_files SET ext = ?, filename = ? WHERE id = ?",
				[$newExt, $newName, $fileId],
				'boolean'
			);

			$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'svg'];
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
