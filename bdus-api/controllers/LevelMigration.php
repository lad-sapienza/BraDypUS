<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus\Controllers;

/**
 * Moves users and API keys from a data level (Writer, Self writer, Reader) to level
 * Enter plus the equivalent system group, and back. Admin only.
 *
 * The move is refused unless the group gives exactly the same access as the level on
 * every table (`not_equivalent`, with the differences) and can be undone: the old
 * level is kept (M058). See {@see \Auth\LevelMigration} for the rules and the codes.
 *
 * Errors follow the usual envelope: HTTP 200, `status: error`, i18n `code`.
 */
class LevelMigration extends \Bdus\Controller
{
    /** POST /api/user/{id}/move-to-group */
    public function moveUser(): void
    {
        $this->run(fn($m, $id) => $m->moveUser($id), 'user');
    }

    /** POST /api/user/{id}/restore-level */
    public function restoreUser(): void
    {
        $this->run(fn($m, $id) => $m->restoreUser($id), 'user');
    }

    /** POST /api/api-key/{id}/move-to-group */
    public function moveKey(): void
    {
        $this->run(fn($m, $id) => $m->moveKey($id), 'API key');
    }

    /** POST /api/api-key/{id}/restore-level */
    public function restoreKey(): void
    {
        $this->run(fn($m, $id) => $m->restoreKey($id), 'API key');
    }

    /**
     * POST /api/levels/move-to-groups { dry_run?: bool }
     * → { moved: [{type, id, name, level, group}], refused: [{type, id, name, code, differences?}], dry_run }
     */
    public function moveAll(): void
    {
        if (!\Auth\Authorization::can('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $dry = filter_var($this->post['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $res = (new \Auth\LevelMigration($this->db, $this->cfg))->moveAll($dry);

        foreach ($res['moved'] as $m) {
            if (!$dry) {
                $this->log?->info(sprintf('Level migration: %s #%d level %d → Enter + group %s', $m['type'], $m['id'], $m['level'], $m['group']));
            }
        }
        $this->returnJson(['status' => 'success'] + $res);
    }

    private function run(callable $action, string $what): void
    {
        if (!\Auth\Authorization::can('admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return;
        }
        $id = (int) ($this->get['id'] ?? 0);
        if (!$id) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'id']);
            return;
        }

        $res = $action(new \Auth\LevelMigration($this->db, $this->cfg), $id);

        if (!$res['ok']) {
            if ($res['code'] === 'db_error') {
                $this->log?->error($res['error'] ?? 'level migration failed');
            }
            unset($res['ok'], $res['error']);
            $this->returnJson(['status' => 'error'] + $res);
            return;
        }
        $this->log?->info(sprintf(
            'Level migration: %s #%d %s (level %d%s)',
            $what, $id, $res['code'], $res['level'], isset($res['group']) ? ', group ' . $res['group'] : ''
        ));
        unset($res['ok']);
        $this->returnJson(['status' => 'success'] + $res);
    }
}
