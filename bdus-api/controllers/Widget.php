<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Serves per-app UI widget files from projects/{app}/widgets/
 *
 * Widgets are vanilla ES modules that the frontend loads dynamically.
 * Each widget file must export a default object with a mount(container, value)
 * method and an optional unmount(container) method.
 */

class Widget extends \Bdus\Controller
{
    /**
     * GET /api/widgets
     * Returns the list of available widget names for the current app.
     */
    public function listWidgets(): void
    {
        if (!\Auth\Authorization::can('read')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $widgetDir = $this->widgetDir();
        if (!$widgetDir || !is_dir($widgetDir)) {
            $this->returnJson(['widgets' => []]);
            return;
        }

        $names = [];
        foreach (glob($widgetDir . '*.js') as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if ($this->isValidName($name)) {
                $names[] = $name;
            }
        }
        sort($names);

        $this->returnJson(['widgets' => $names]);
    }

    /**
     * GET /api/widget/{name}
     * Serves the widget JS file with the correct Content-Type.
     */
    public function serveWidget(): void
    {
        if (!\Auth\Authorization::can('read')) {
            http_response_code(403);
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $name = $this->get['name'] ?? '';

        if (!$this->isValidName($name)) {
            http_response_code(400);
            $this->returnJson(['status' => 'error', 'code' => 'invalid_widget_name']);
            return;
        }

        $widgetDir = $this->widgetDir();
        $file      = $widgetDir . $name . '.js';

        if (!$widgetDir || !is_file($file)) {
            http_response_code(404);
            $this->returnJson(['status' => 'error', 'code' => 'widget_not_found']);
            return;
        }

        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: private, max-age=300');
        readfile($file);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function widgetDir(): ?string
    {
        if (!defined('PROJ_DIR')) {
            return null;
        }
        return PROJ_DIR . 'widgets/';
    }

    /** Only lowercase letters, digits and dashes — prevents path traversal. */
    private function isValidName(string $name): bool
    {
        return $name !== '' && preg_match('/^[a-z0-9\-]+$/', $name) === 1;
    }
}
