<?php

namespace Bdus\Controllers;

use Pleiades\Client;
use Pleiades\PleiadesException;

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 *
 * Proxies the public Pleiades gazetteer (https://pleiades.stoa.org) so the
 * frontend never calls it directly (no CORS, no third-party API shape leaking
 * into the client contract). Read-only; no local persistence — the record
 * that ends up carrying a `pleiades_id`/`pleiades_label`/`pleiades_alt_label`
 * is saved through the normal record-save endpoint like any other field, see
 * `feature_pleiades_plugin_design.md`.
 *
 * Routes (see Router.php):
 *   GET /api/pleiades/search        — search-as-you-type suggestions
 *   GET /api/pleiades/place/{id}    — full place lookup (id + title + reprPoint)
 */
class Pleiades extends \Bdus\Controller
{
    /**
     * GET /api/pleiades/search?q=...
     * Response: { status, results: [{id, title, snippet}, ...] }
     */
    public function search(): void
    {
        if (!\Auth\Authorization::can('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $q = trim($this->get['q'] ?? '');
        if ($q === '') {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        try {
            $results = (new Client())->search($q);
            $this->returnJson(['status' => 'success', 'results' => $results]);
        } catch (PleiadesException $e) {
            $this->log->error('Pleiades search error: ' . $e->getMessage());
            $this->returnJson(['status' => 'error', 'code' => 'pleiades_api_error', 'detail' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/pleiades/place/{id}
     * Response: { status, place: {id, title, reprPoint} }
     */
    public function getPlace(): void
    {
        if (!\Auth\Authorization::can('edit')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }

        $id = (int) ($this->get['id'] ?? 0);
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing']);
            return;
        }

        try {
            $place = (new Client())->getPlace($id);
            $this->returnJson(['status' => 'success', 'place' => $place]);
        } catch (PleiadesException $e) {
            $this->log->error('Pleiades getPlace error: ' . $e->getMessage());
            $this->returnJson(['status' => 'error', 'code' => 'pleiades_api_error', 'detail' => $e->getMessage()]);
        }
    }
}
