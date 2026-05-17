<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Aug 11, 2012
 */

class frontpage_editor_ctrl extends Controller
{
	/**
	 * Returns the path of the welcome content file.
	 * Supports both the legacy .html and the new .md extension.
	 */
	private function getFile(): string
	{
		$md   = PROJ_DIR . 'welcome.md';
		$html = PROJ_DIR . 'welcome.html';
		// Prefer the new .md file; fall back to legacy .html
		return file_exists($md) ? $md : $html;
	}

	// ── v5 API endpoints ──────────────────────────────────────────────────────

	/**
	 * GET ?obj=frontpage_editor_ctrl&method=getWelcome
	 * Returns the welcome text (MD or HTML) as JSON.
	 * No auth required — visible on the dashboard to all logged-in users.
	 *
	 * Response: { "content": "<string>" }
	 */
	public function getWelcome(): void
	{
		$file = $this->getFile();
		$content = file_exists($file) ? file_get_contents($file) : '';
		$this->returnJson(['content' => $content]);
	}

	/**
	 * POST ?obj=frontpage_editor_ctrl&method=saveWelcome
	 * Saves the welcome text. Admin-only.
	 * Body: { "content": "<string>" }
	 *
	 * Response: { "status": "success", "code": "ok_save" }
	 */
	public function saveWelcome(): void
	{
		if (!\utils::canUser('adm')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$content = $this->post['content'] ?? '';
		// Strip PHP tags — HTML and MD are allowed, PHP execution is not
		$content = str_replace(['<?php', '<?', '?>'], '', $content);

		$file = PROJ_DIR . 'welcome.md';
		if (file_put_contents($file, $content) === false) {
			$this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => 'Cannot write welcome.md']);
			return;
		}

		$this->returnJson(['status' => 'success', 'code' => 'ok_save']);
	}

	// ── Legacy v4 methods ─────────────────────────────────────────────────────

	/** @deprecated v5 — replaced by getWelcome() */
	public function get_content()
	{
		$file = $this->getFile();
		echo file_get_contents($file);
	}

	/** @deprecated v5 — replaced by saveWelcome() */
	public function save_content()
	{
		$text = $this->post['text'];
		$file = $this->getFile();
		$text = stripslashes($text);
		$text = str_replace(['<?php', '<?', '?>'], '', $text);
		file_put_contents($file, $text);
	}
}