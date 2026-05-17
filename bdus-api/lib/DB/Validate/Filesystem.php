<?php

namespace DB\Validate;

/**
 * Filesystem security checks.
 *
 * Verifies that sensitive directories are protected from direct web access
 * via an .htaccess file.
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
     * Check that cfg/ has a deny-all .htaccess.
     * If it is missing, write it automatically and report the fix.
     */
    public function cfgDirProtected(): void
    {
        $htaccess = PROJ_DIR . 'cfg/.htaccess';

        if (file_exists($htaccess)) {
            $this->resp->set(
                'success',
                'cfg/ directory has .htaccess protection'
            );
            return;
        }

        // Attempt self-healing
        $written = \DB\System\CreateApp::writeCfgHtaccess(PROJ_DIR . 'cfg');

        if ($written) {
            $this->resp->set(
                'warning',
                'cfg/.htaccess was missing — created automatically. '
                . 'Verify your web server honours .htaccess files.'
            );
        } else {
            $this->resp->set(
                'danger',
                'cfg/.htaccess is missing and could not be written automatically. '
                . 'Create it manually to prevent web access to sensitive configuration files.',
                'Write cfg/.htaccess',
                ['write_cfg_htaccess']
            );
        }
    }
}
