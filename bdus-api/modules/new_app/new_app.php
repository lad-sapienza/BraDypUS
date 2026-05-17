<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since 4.0.0
 */

class new_app_ctrl extends Controller
{
    // ── Access guard ─────────────────────────────────────────────────────────

    /**
     * App creation is permitted when:
     *   a) The env variable BRADYPUS_ALLOW_NEW_APP is set to '1', OR
     *   b) The projects/ directory is empty (first-time / fresh install)
     *
     * Set the env variable in docker-compose.yml, .env, or your server
     * config, and remove it again once the app has been created.
     */
    private function isPermitted(): bool
    {
        return getenv('BRADYPUS_ALLOW_NEW_APP') === '1'
            || !\utils::dirContent(MAIN_DIR . 'projects');
    }

    // ── v5 JSON endpoints ────────────────────────────────────────────────────

    /**
     * Returns whether app creation is currently permitted and the list of
     * available DB engines. No authentication required.
     *
     * GET ?obj=new_app_ctrl&method=getStatus
     * Response: { status, permitted: bool, engines: string[] }
     */
    public function getStatus(): void
    {
        $this->returnJson([
            'status'    => 'success',
            'permitted' => $this->isPermitted(),
            'engines'   => \DB\Engines\AvailableEngines::getList(),
        ]);
    }

    /**
     * Creates a new BraDypUS application. No authentication required;
     * access is controlled by isPermitted().
     *
     * POST ?obj=new_app_ctrl&method=create
     * Body: {
     *   name, definition, email, password, db_engine,
     *   db_host?, db_port?, db_name?, db_username?, db_password?
     * }
     * Response: { status, code, log?: string[] }
     */
    public function create(): void
    {
        if (!$this->isPermitted()) {
            $this->returnJson(['status' => 'error', 'code' => 'not_allowed_app_create']);
            return;
        }

        $name        = $this->post['name']        ?? null;
        $definition  = $this->post['definition']  ?? null;
        $email       = $this->post['email']        ?? null;
        $password    = $this->post['password']     ?? null;
        $db_engine   = $this->post['db_engine']   ?? null;
        $db_host     = $this->post['db_host']     ?? null;
        $db_port     = $this->post['db_port']     ?? null;
        $db_name     = $this->post['db_name']     ?? null;
        $db_username = $this->post['db_username'] ?? null;
        $db_password = $this->post['db_password'] ?? null;

        try {
            $createApp = new \DB\System\CreateApp(
                $name,
                $definition,
                $email,
                $password,
                $db_engine,
                $db_host,
                $db_port,
                $db_name,
                $db_username,
                $db_password
            );
            $createApp->createAll();

            $this->returnJson([
                'status' => 'success',
                'code'   => 'ok_app_created',
                'log'    => $createApp->getLog(),
            ]);

        } catch (\Throwable $e) {
            if ($this->log) {
                $this->log->error($e);
            }
            $this->returnJson([
                'status' => 'error',
                'code'   => 'error_app_not_created',
                'detail' => $e->getMessage(),
            ]);
        }
    }

    // ── v4 methods (deprecated) ──────────────────────────────────────────────

    /** @deprecated v5 — use getStatus() + create() */
    public function new_app_form()
    {
        $AvailableEngines = new \DB\Engines\AvailableEngines();
        if ($this->isPermitted()) {
            $this->render('new_app', 'new_app_form', [
                "db_engines" => $AvailableEngines->getList()
            ]);
        } else {
            echo \tr::get('not_allowed_app_create');
        }
    }

    /** @deprecated v5 — use create() */
    public function add_app()
    {
        try {
            $createApp = new \DB\System\CreateApp(
                $this->post['name'],
                $this->post['definition'],
                $this->post['your_email'],
                $this->post['your_password'],
                $this->post['db_engine'],
                $this->post['db_host']     ?? null,
                $this->post['db_port']     ?? null,
                $this->post['db_name']     ?? null,
                $this->post['db_username'] ?? null,
                $this->post['db_password'] ?? null
            );
            $createApp->createAll();
            $this->response('ok_app_created', 'success', null, ['log' => $createApp->getLog()]);
        } catch (\Throwable $e) {
            $this->response('error_app_not_created', 'error', [$e->getMessage()]);
            $this->log->error($e);
        }
    }
}
