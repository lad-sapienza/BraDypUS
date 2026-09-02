<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since 4.0.0
 */

class NewApp extends \Bdus\Controller
{
    // ── Access guard ─────────────────────────────────────────────────────────

    /**
     * App creation over HTTP is permitted only when the env variable
     * BRADYPUS_ALLOW_NEW_APP is set to '1' — including the very first app.
     * Set it (docker-compose.yml, .env, server config) just long enough to
     * create the app, then set it back to '0'.
     *
     * For an unattended path with no open window, use the CLI instead:
     *   docker compose exec api php bin/create-app.php --name … --engine …
     */
    private function isPermitted(): bool
    {
        return getenv('BRADYPUS_ALLOW_NEW_APP') === '1';
    }

    // ── v5 JSON endpoints ────────────────────────────────────────────────────

    /**
     * Returns whether app creation is currently permitted and the list of
     * available DB engines. No authentication required.
     *
     * GET /api/new-app/status
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
     * POST /api/new-app
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

}
