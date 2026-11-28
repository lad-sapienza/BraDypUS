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
	/** Max files returned by getFiles(ids_only=1). */
	private const IDS_ONLY_CAP = 5000;

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
	 * GET /api/files?page=1&per_page=25&orphans_only=1&public=1&search=foo&sort_field=filename&sort_dir=asc
	 *
	 * `search` matches (case-insensitively, via LIKE) against filename,
	 * description and keywords; if it's a plain integer it also matches the
	 * file id exactly.
	 *
	 * Response: { status, total, page, per_page, files: [{ id, uuid, ext, filename,
	 *   description, keywords, is_image,
	 *   links: [{ tb, record_id }] }] }
	 */
	public function getFiles(): void
	{
		// What the principal may see is decided by Auth\FileAccess: files it is granted directly
		// (a rule on bdus_files) plus files attached to records it may read.
		[$readSql, $readVals] = $this->fileAccess()->readCondition('f');
		if ($readSql === '1=0') {
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
				$like      = \SQL\Like::operator($this->db->getEngine());
				// filename, description, keywords, attribution, and every other configured text field
				$textFlds  = ['filename', 'description', 'keywords', 'attribution'];
				foreach (\File\Fields::all($this->cfg) as $name => $type) {
					if (in_array($type, ['text', 'long_text', 'md'], true) && !in_array($name, $textFlds, true)
						&& !in_array($name, \File\Fields::PROTECTED, true)) {
						$textFlds[] = $name;
					}
				}
				$searchOr  = array_map(fn($c) => "f.{$c} {$like} ?", $textFlds);
				$searchVal = array_fill(0, count($textFlds), $needle);
				if (ctype_digit($search)) {
					$searchOr[]  = 'f.id = ?';
					$searchVal[] = (int) $search;
				}
				$conditions[] = '(' . implode(' OR ', $searchOr) . ')';
				$params = array_merge($params, $searchVal);
			}

			// public=1 / public=0: files that public presets may / may not show
			$publicFilter = $this->get['public'] ?? null;
			if ($publicFilter === '1' || $publicFilter === '0' || $publicFilter === 1 || $publicFilter === 0) {
				// A literal (0 or 1, cast): a bound value is text, and text never equals the integer a
				// COALESCE() yields on SQLite
				$conditions[] = 'COALESCE(f.public, 0) = ' . ((int) $publicFilter === 1 ? '1' : '0');
			}

			if ($readSql !== '1=1') {
				$conditions[] = $readSql;
				$params       = array_merge($params, $readVals);
			}

			// Order: a configured column (the same order serves the list and the Previous/Next ids),
			// else the newest first. The column name ends up in SQL: only configured names pass.
			$sortField = (string) ($this->get['sort_field'] ?? '');
			$sortDir   = strtolower((string) ($this->get['sort_dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
			$orderSql  = 'f.id DESC';
			if ($sortField !== '' && array_key_exists($sortField, \File\Fields::all($this->cfg))) {
				$orderSql = "f.{$sortField} {$sortDir}, f.id DESC";
			}

			$whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

			$countSql = "SELECT COUNT(*) AS cnt FROM bdus_files f {$whereSql}";
			// The configured columns (the system ones and any custom field), nothing else
			$cols     = array_keys(\File\Fields::all($this->cfg));
			$colsSql  = implode(', ', array_map(fn($c) => "f.{$c}", array_unique(array_merge(['id', 'uuid', 'ext', 'filename'], $cols))));
			$fetchSql = "SELECT {$colsSql}
			             FROM bdus_files f {$whereSql}
			             ORDER BY {$orderSql} LIMIT ? OFFSET ?";

			$countRow  = $this->db->query($countSql, $params, 'read');
			$total     = (int)($countRow[0]['cnt'] ?? 0);

			// ids_only: the minimal descriptor of EVERY matching file (not one
			// page), same filter and order as the list — for the preview's
			// Previous/Next navigation. Capped; `truncated` flags a partial list.
			if (!empty($this->get['ids_only'])) {
				$cap  = self::IDS_ONLY_CAP;
				$rows = $this->db->query(
					"SELECT f.id, f.uuid, f.ext, f.filename FROM bdus_files f {$whereSql} ORDER BY {$orderSql} LIMIT ?",
					[...$params, $cap],
					'read'
				) ?: [];
				$files = [];
				foreach ($rows as $r) {
					$ext     = $r['ext'] ?? '';
					$files[] = [
						'id'       => (int)$r['id'],
						'uuid'     => $r['uuid'],
						'ext'      => $ext,
						'filename' => $r['filename'] ?? '',
						'is_image' => in_array(strtolower($ext), $imageExts, true),
					];
				}
				$this->returnJson([
					'status'    => 'success',
					'total'     => $total,
					'files'     => $files,
					'truncated' => $total > count($files),
				]);
				return;
			}

			$rows      = $this->db->query($fetchSql, [...$params, $perPage, $offset], 'read') ?: [];

			if (empty($rows)) {
				$this->returnJson([
					'status'   => 'success',
					'total'    => $total,
					'page'     => $page,
					'per_page' => $perPage,
					'fields'   => \File\Fields::describe($this->cfg),
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

			// Show only the links to records the principal may read.
			$linkRows = $this->fileAccess()->readableLinks($linkRows);

			$linkMap   = [];
			foreach ($linkRows as $lr) {
				$linkMap[(int)$lr['file_id']][] = ['tb' => $lr['table_name'], 'record_id' => (int)$lr['record_id']];
			}

			$types = \File\Fields::all($this->cfg);
			$fa    = $this->fileAccess();
			$files = [];
			foreach ($rows as $r) {
				$id   = (int)$r['id'];
				$ext  = $r['ext'] ?? '';
				// The configured fields (system and custom), in the configured order; booleans as booleans
				$file = ['id' => $id, 'uuid' => $r['uuid'], 'ext' => $ext, 'filename' => $r['filename'] ?? ''];
				foreach ($types as $name => $type) {
					if (in_array($name, ['id', 'uuid', 'ext', 'filename'], true) || !array_key_exists($name, $r)) {
						continue;
					}
					$file[$name] = $type === 'boolean' && $r[$name] !== null ? (bool) $r[$name] : $r[$name];
				}
				$file['is_image']   = in_array(strtolower($ext), $imageExts, true);
				$file['links']      = $linkMap[$id] ?? [];
				// What this principal may do with it, so the interface shows only what works
				$file['can_update'] = $fa->mayChange('update', $id);
				$file['can_delete'] = $fa->mayChange('delete', $id);
				$files[] = $file;
			}

			$this->returnJson([
				'status'   => 'success',
				'total'    => $total,
				'page'     => $page,
				'per_page' => $perPage,
				'fields'   => \File\Fields::describe($this->cfg),
				'files'    => $files,
			]);

		} catch (\Throwable $e) {
			$this->log->error($e);
			$this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
		}
	}

	/**
	 * Updates file metadata (filename, description, keywords, attribution).
	 *
	 * Renaming only ever touches this DB column — the physical file keeps
	 * living at `{id}.{ext}` regardless of `filename` (see uploadFile()/
	 * replaceFile()), so a rename is a pure metadata edit, no filesystem or
	 * URL implications.
	 *
	 * PATCH /api/file/{fileId}
	 * Body: { filename?, description?, keywords?, attribution? }
	 *
	 * Response: { status, code }
	 */
	public function updateFile(): void
	{
		// Cheap pre-gate: nothing at all to change anywhere. The per-file rules follow.
		if (!\Auth\Authorization::canOnSomeTable('edit')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$fileId = (int)($this->get['fileId'] ?? 0);
		if (!$fileId) {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}

		$rows = $this->db->query("SELECT id, uuid FROM bdus_files WHERE id = ?", [$fileId], 'read');
		if (empty($rows)) {
			$this->returnJson(['status' => 'error', 'code' => 'record_not_found']);
			return;
		}
		if (!$this->fileAccess()->mayChange('update', $fileId)) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}
		$uuid = (string) $rows[0]['uuid'];

		if (array_key_exists('filename', $this->post) && trim((string) $this->post['filename']) === '') {
			$this->returnJson(['status' => 'error', 'code' => 'filename_required']);
			return;
		}

		// Every configured field of the files table may be sent (the system ones and any custom
		// field the administrator added), except the ones that belong to the binary: Record\Save
		// drops those, validates the rest, writes it and keeps the history.
		$core = [];
		foreach (\File\Fields::editable($this->cfg) as $name => $type) {
			if (!array_key_exists($name, $this->post)) {
				continue;
			}
			$value = $this->post[$name];
			if ($type === 'boolean') {
				$value = (int) (bool) $value;
			} elseif ($name === 'filename') {
				$value = trim((string) $value);
			}
			$core[$name] = $value;
		}

		if (empty($core)) {
			$this->returnJson(['status' => 'success', 'code' => 'ok_file_updated']);
			return;
		}

		$res = (new \Record\Save($this->db, $this->cfg, $this->access(), $this->log))
			->save(\Auth\FileAccess::TABLE, $fileId, $core, []);

		if (($res['status'] ?? '') === 'success') {
			// Metadata (e.g. attribution) may feed derived versions: they die with their parent
			\Image\Derivative::purgeFile(PROJ_DIR, $uuid);
			$this->returnJson(['status' => 'success', 'code' => 'ok_file_updated']);
			return;
		}

		// A refusal keeps its own code (and the fields that failed validation); a failure to
		// write keeps the code this endpoint always had.
		$code = $res['code'] ?? 'error_file_updated';
		$this->returnJson(array_merge(
			['status' => 'error', 'code' => $code === 'error_saved' ? 'error_file_updated' : $code],
			isset($res['errors']) ? ['errors' => $res['errors']] : []
		));
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
		// Cheap pre-gate: nothing at all to change anywhere. The per-file rules follow.
		if (!\Auth\Authorization::canOnSomeTable('edit')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$fileId = (int)($this->get['fileId'] ?? 0);
		if (!$fileId) {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}
		if (!$this->fileAccess()->mayChange('update', $fileId)) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
			$this->returnJson(['status' => 'error', 'code' => 'error_uploading_file']);
			return;
		}

		$rows = $this->db->query("SELECT uuid, ext FROM bdus_files WHERE id = ?", [$fileId], 'read');
		if (empty($rows)) {
			$this->returnJson(['status' => 'error', 'code' => 'record_not_found']);
			return;
		}
		$uuid   = (string) $rows[0]['uuid'];
		$oldExt = (string) $rows[0]['ext'];

		try {
			$original = basename($_FILES['file']['name']);
			$newExt   = strtolower(pathinfo($original, PATHINFO_EXTENSION));
			$newName  = pathinfo($original, PATHINFO_FILENAME);

			// The uuid is kept: replacing the binary does not change the file's identity.
			$newPath  = \File\Store::path(PROJ_DIR, $uuid, $newExt);

			// The binary changes: derived versions die with their parent
			\Image\Derivative::purgeFile(PROJ_DIR, $uuid);

			if (!move_uploaded_file($_FILES['file']['tmp_name'], $newPath)) {
				throw new \RuntimeException('move_uploaded_file failed');
			}

			// Delete old physical file if extension changed
			if ($oldExt !== $newExt) {
				$oldPath = \File\Store::path(PROJ_DIR, $uuid, $oldExt);
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
	 * Sends the original binary of a file.
	 *
	 * GET /{app}/api/file/{uuid}
	 *
	 * Needs a signed-in principal (Bearer token, API key, or, for people in a browser, the
	 * session cookie) who may read the file (Auth\FileAccess). Every refusal is a plain 404:
	 * an address that cannot be read looks exactly like one that does not exist.
	 * Supports ETag revalidation; sends only safe-to-display kinds inline (File\Delivery).
	 */
	public function download(): void
	{
		$notFound = static function (): void {
			http_response_code(404);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Not found';
		};

		try {
			$uuid = strtolower((string) ($this->get['uuid'] ?? ''));
			if (!\File\Store::isUuid($uuid)) {
				$notFound();
				return;
			}

			$rows = $this->db->query('SELECT id, ext, filename FROM bdus_files WHERE uuid = ?', [$uuid], 'read');
			if (!$rows || !$this->fileAccess()->mayRead((int) $rows[0]['id'])) {
				$notFound();
				return;
			}

			$ext  = (string) $rows[0]['ext'];
			$path = \File\Store::path(PROJ_DIR, $uuid, $ext);
			if (!is_file($path)) {
				$notFound();
				return;
			}

			$mtime = (int) filemtime($path);
			$size  = (int) filesize($path);
			$etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';

			foreach (\File\Delivery::headers((string) $rows[0]['filename'], $ext) as $name => $value) {
				header($name . ': ' . $value);
			}
			header('ETag: ' . $etag);
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

			if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
				http_response_code(304);
				return;
			}

			header('Content-Length: ' . $size);
			readfile($path);
		} catch (\Throwable $e) {
			$this->log->error($e);
			$notFound();
		}
	}

	/**
	 * Serves a derived (resized) version of an image, creating it on first request.
	 *
	 * GET /{app}/api/file/{preset}/{uuid}.{ext}   — public only for a public preset and a public file.
	 *
	 * Only names that match a defined preset are ever generated, so the set of
	 * possible versions is finite. Every failure (unknown preset, missing file,
	 * extension not matching the preset format, original not processable) is a
	 * plain 404: no fallback to the original.
	 */
	public function serveDerived(): void
	{
		$name = (string) ($this->get['preset'] ?? '');
		$uuid = strtolower((string) ($this->get['uuid'] ?? ''));
		$ext  = strtolower((string) ($this->get['ext'] ?? ''));

		$notFound = static function (): void {
			http_response_code(404);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Not found';
		};

		try {
			$preset = \Image\Presets::find(
				\Image\Presets::resolve((array) ($this->cfg->get('main.imagePresets') ?? [])),
				$name
			);
			// A disabled preset is not served at all (its definition and cache stay)
			if (!$preset || !\Image\Presets::isEnabled($preset) || !\File\Store::isUuid($uuid)
				|| $ext !== \Image\Presets::extension($preset)) {
				$notFound();
				return;
			}

			$rows = $this->db->query('SELECT id, ext, attribution, public FROM bdus_files WHERE uuid = ?', [$uuid], 'read');
			$src  = $rows ? strtolower((string) $rows[0]['ext']) : '';
			$attribution = $rows ? ($rows[0]['attribution'] ?? null) : null;
			if ($src === '' || !\Image\Derivative::isSupportedSource($src)) {
				$notFound();
				return;
			}

			// Who may be sent it: anyone, when the preset is public AND the file is marked public (the
			// only way an image leaves the system anonymously); otherwise whoever may read the original.
			// Every other case looks exactly like an address that does not exist.
			$anonymousOk = \Image\Presets::isPublic($preset) && (int) ($rows[0]['public'] ?? 0) === 1;
			if (!$anonymousOk
				&& !(\Auth\CurrentUser::isAuthenticated() && $this->fileAccess()->mayRead((int) $rows[0]['id']))) {
				$notFound();
				return;
			}

			$srcPath = \File\Store::path(PROJ_DIR, $uuid, $src);
			if (!is_file($srcPath)) {
				$notFound();
				return;
			}

			$dest = \Image\Derivative::cachePath(PROJ_DIR, $preset['name'], $uuid, $ext);

			// Regenerate when missing or older than the original (safety net for
			// manual replacement outside the app).
			if (!is_file($dest) || filemtime($dest) < filemtime($srcPath)) {
				// A source that failed to process is not retried (nor logged again)
				// until it changes: the marker is purged with the file's other versions.
				$fail = $dest . '.fail';
				if (is_file($fail) && filemtime($fail) >= filemtime($srcPath)) {
					$notFound();
					return;
				}
				try {
					\Image\Derivative::generate($srcPath, $dest, $preset, $attribution);
				} catch (\Throwable $e) {
					@mkdir(dirname($dest), 0775, true);
					@touch($fail);
					throw $e;
				}
				@unlink($fail);
				clearstatcache(true, $dest);
			}

			$mtime = (int) filemtime($dest);
			$size  = (int) filesize($dest);
			$etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';

			header('ETag: ' . $etag);
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
			// A shared cache may keep what anyone may be sent; anything else stays with the one who asked
			header('Cache-Control: ' . ($anonymousOk ? 'public' : 'private') . ', no-cache');
			header('Vary: Authorization, Cookie');

			if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
				http_response_code(304);
				return;
			}

			header('Content-Type: ' . ($ext === 'jpg' ? 'image/jpeg' : 'image/webp'));
			header('Content-Length: ' . $size);
			readfile($dest);
		} catch (\Throwable $e) {
			$this->log->error($e);
			$notFound();
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
		// Cheap pre-gate: nothing at all to change anywhere. The per-file rules follow.
		if (!\Auth\Authorization::canOnSomeTable('edit')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$order = $this->post['order'] ?? [];
		if (!is_array($order) || empty($order)) {
			$this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
			return;
		}

		// Reordering a gallery changes the record it belongs to: every link must be on a
		// record the principal may change (a link that does not exist is refused too).
		$linkIds = array_values(array_unique(array_map('intval', $order)));
		$marks   = implode(',', array_fill(0, count($linkIds), '?'));
		$links   = $this->db->query(
			"SELECT id, table_name, record_id FROM bdus_file_links WHERE id IN ({$marks})",
			$linkIds,
			'read'
		) ?: [];
		if (count($links) !== count($linkIds)) {
			$this->returnJson(['status' => 'error', 'code' => 'record_not_found']);
			return;
		}
		foreach ($links as $l) {
			if (!$this->mayChange('update', (string) $l['table_name'], (int) $l['record_id'])) {
				$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
				return;
			}
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
