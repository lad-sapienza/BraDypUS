<?php

namespace DB\Validate;

/**
 * Filesystem security checks.
 *
 * Verifies that the project root .htaccess protects config.json and
 * .jwt_secret from direct web access.
 *
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */
class Filesystem
{
    private Resp $resp;

    public function __construct(Resp $resp)
    {
        $this->resp = $resp;
    }

    /**
     * Check that the project root has a <Files>-based .htaccess that blocks
     * direct web access to config.json and .jwt_secret.
     *
     * In v5 the sensitive configuration lives at projects/{app}/config.json
     * (project root), not inside cfg/.  The protection is a <Files>-based
     * .htaccess written by CreateApp::writeProjectHtaccess().
     *
     * If the file is missing or stale, it is rewritten automatically.
     */
    public function cfgDirProtected(): void
    {
        $projDir  = rtrim(PROJ_DIR, '/');
        $htaccess = $projDir . '/.htaccess';

        if (file_exists($htaccess) &&
            str_contains((string) file_get_contents($htaccess), '<Files "config.json">')) {
            $this->resp->set(
                'success',
                'Project root .htaccess protects config.json and .jwt_secret from web access'
            );
            return;
        }

        // Attempt self-healing: write (or overwrite) the project-root .htaccess.
        $written = \DB\System\CreateApp::writeProjectHtaccess($projDir);

        if ($written) {
            $this->resp->set(
                'warning',
                'Project root .htaccess was missing or outdated — rewritten automatically. '
                . 'Verify that your web server honours .htaccess files.'
            );
        } else {
            $this->resp->set(
                'danger',
                'Project root .htaccess is missing and could not be written automatically. '
                . 'Create it manually in projects/' . basename($projDir) . '/ '
                . 'to prevent direct web access to config.json and .jwt_secret.'
            );
        }
    }
}
