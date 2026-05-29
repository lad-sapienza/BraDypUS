<?php
/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Aug 11, 2012
 */

class frontpage_editor_ctrl extends Controller
{
	// ── v5 API endpoints ──────────────────────────────────────────────────────

	/**
	 * GET ?obj=frontpage_editor_ctrl&method=getWelcome
	 * Returns the welcome Markdown text as JSON.
	 * No auth required — visible on the dashboard to all logged-in users.
	 *
	 * Post-M019: read from bdus_cfg_app.welcome.
	 * Pre-M019 fallback: read from welcome.md / welcome.html on disk.
	 *
	 * Response: { "content": "<string>" }
	 */
	public function getWelcome(): void
	{
		if (\Config\AppSettings::isAvailable($this->db)) {
			$content = \Config\AppSettings::getWelcome($this->db);
		} else {
			// Legacy file fallback — removed by M019 on existing installations.
			$md   = PROJ_DIR . 'welcome.md';
			$html = PROJ_DIR . 'welcome.html';
			$file = file_exists($md) ? $md : $html;
			$content = file_exists($file) ? file_get_contents($file) : '';
		}
		$this->returnJson(['content' => $content]);
	}

	/**
	 * POST ?obj=frontpage_editor_ctrl&method=saveWelcome
	 * Saves the welcome Markdown text. Admin-only.
	 * Body: { "content": "<string>" }
	 *
	 * Response: { "status": "success", "code": "ok_save" }
	 */
	public function saveWelcome(): void
	{
		if (!\Auth\Authorization::can('admin')) {
			$this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
			return;
		}

		$content = $this->post['content'] ?? '';
		// Strip PHP tags — HTML and MD are allowed, PHP execution is not.
		$content = str_replace(['<?php', '<?', '?>'], '', $content);

		try {
			\Config\AppSettings::saveWelcome($this->db, $content);
			$this->returnJson(['status' => 'success', 'code' => 'ok_save']);
		} catch (\Throwable $e) {
			$this->returnJson(['status' => 'error', 'code' => 'db_error', 'detail' => $e->getMessage()]);
		}
	}

}