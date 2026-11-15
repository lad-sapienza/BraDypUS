<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus\Controllers;

use Mcp\PromptError;
use Mcp\Prompts;

/**
 * Administration of the prompts an application offers to MCP clients
 * (bdus_mcp_prompts, M054). Super-admin only, like the MCP settings: the
 * texts end up in a model's context. MCP clients themselves read prompts
 * through `prompts/list` and `prompts/get` and can never write them.
 */
class McpPrompts extends \Bdus\Controller
{
    /** GET — { status, prompts: [ { id, name, title, description, arguments, template, sort } ] } */
    public function listPrompts(): void
    {
        if (!$this->allowed()) {
            return;
        }
        $this->returnJson(['status' => 'success', 'prompts' => (new Prompts($this->db))->all()]);
    }

    /** POST { name, title?, description?, arguments?, template } */
    public function createPrompt(): void
    {
        if (!$this->allowed()) {
            return;
        }
        $this->guarded(function (): array {
            $prompt = (new Prompts($this->db))->create($this->post);
            return ['status' => 'success', 'code' => 'ok_mcp_prompt_saved', 'prompt' => $prompt];
        });
    }

    /** PUT { name, title?, description?, arguments?, template } — replaces the content of prompt {id} */
    public function updatePrompt(): void
    {
        if (!$this->allowed()) {
            return;
        }
        $id = (int) ($this->request['id'] ?? 0);
        $this->guarded(function () use ($id): array {
            $prompt = (new Prompts($this->db))->update($id, $this->post);
            return $prompt === null
                ? ['status' => 'error', 'code' => 'prompt_not_found']
                : ['status' => 'success', 'code' => 'ok_mcp_prompt_saved', 'prompt' => $prompt];
        });
    }

    public function deletePrompt(): void
    {
        if (!$this->allowed()) {
            return;
        }
        $id = (int) ($this->request['id'] ?? 0);
        $this->guarded(fn(): array => (new Prompts($this->db))->delete($id)
            ? ['status' => 'success', 'code' => 'ok_mcp_prompt_deleted']
            : ['status' => 'error', 'code' => 'prompt_not_found']);
    }

    /** PUT { ids: [ … ] } — the listed prompts come first, in that order */
    public function reorderPrompts(): void
    {
        if (!$this->allowed()) {
            return;
        }
        $ids = $this->post['ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids)) {
            $this->returnJson(['status' => 'error', 'code' => 'parameter_missing', 'detail' => 'ids']);
            return;
        }
        $this->guarded(function () use ($ids): array {
            (new Prompts($this->db))->reorder($ids);
            return ['status' => 'success', 'code' => 'ok_mcp_prompts_reordered'];
        });
    }

    private function allowed(): bool
    {
        if (!\Auth\Authorization::can('super_admin')) {
            $this->returnJson(['status' => 'error', 'code' => 'not_enough_privilege']);
            return false;
        }
        return true;
    }

    /** Runs $fn and answers with its result; a refused prompt or a database failure becomes an error code. */
    private function guarded(callable $fn): void
    {
        try {
            $this->returnJson($fn());
        } catch (PromptError $e) {
            $this->returnJson(array_filter(
                ['status' => 'error', 'code' => $e->errorCode, 'detail' => $e->detail],
                fn($v) => $v !== null
            ));
        } catch (\Throwable $e) {
            // typically: M054 has not been applied yet
            $this->log?->error($e);
            $this->returnJson(['status' => 'error', 'code' => 'error_saved']);
        }
    }
}
