<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Mcp;

use DB\DBInterface;

/**
 * The prompts of an application (bdus_mcp_prompts, M054): text templates with
 * arguments that an MCP client offers to its user ("end-of-season report",
 * "context sheet entry"). Written by the administrator, read by `prompts/list`
 * and `prompts/get`. A prompt grants nothing: it is only text.
 *
 * A template holds `{{argument}}` placeholders. Rendering is a single pass over
 * the template, so a value that itself contains `{{x}}` is never expanded again.
 */
class Prompts
{
    public const MAX_PROMPTS     = 50;
    public const MAX_ARGUMENTS   = 10;
    public const MAX_TITLE       = 100;
    public const MAX_DESCRIPTION = 500;
    public const MAX_ARG_DESC    = 200;
    public const MAX_TEMPLATE    = 20000;
    public const MAX_ARG_VALUE   = 5000;

    private const NAME_RE        = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
    private const ARG_NAME_RE    = '/^[a-z_][a-z0-9_]{0,31}$/';
    private const PLACEHOLDER_RE = '/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/';

    private const COLUMNS = 'id, name, title, description, arguments, template, sort';

    public function __construct(private readonly DBInterface $db) {}

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Every prompt, in the administrator's order. Empty when M054 has not been
     * applied yet: a pending migration must not break the endpoint.
     *
     * @return array<int, array{id: int, name: string, title: string, description: string, arguments: array, template: string, sort: int}>
     */
    public function all(): array
    {
        try {
            $rows = $this->db->query('SELECT ' . self::COLUMNS . ' FROM bdus_mcp_prompts ORDER BY sort, id', [], 'read') ?: [];
        } catch (\Throwable) {
            return [];
        }
        return array_map($this->hydrate(...), $rows);
    }

    public function find(string $name): ?array
    {
        foreach ($this->all() as $prompt) {
            if ($prompt['name'] === $name) {
                return $prompt;
            }
        }
        return null;
    }

    public function get(int $id): ?array
    {
        $row = $this->db->query('SELECT ' . self::COLUMNS . ' FROM bdus_mcp_prompts WHERE id = ?', [$id], 'read');
        return $row ? $this->hydrate($row[0]) : null;
    }

    private function hydrate(array $row): array
    {
        $args = json_decode((string) ($row['arguments'] ?? ''), true);
        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'title'       => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'arguments'   => is_array($args) ? array_values($args) : [],
            'template'    => (string) $row['template'],
            'sort'        => (int) ($row['sort'] ?? 0),
        ];
    }

    // ── Write (administrator) ─────────────────────────────────────────────────

    /**
     * Creates a prompt and returns it.
     *
     * @throws PromptError  the data are not acceptable
     */
    public function create(array $data): array
    {
        $clean = $this->validate($data);
        if ($this->nameTaken($clean['name'], null)) {
            throw new PromptError('prompt_name_taken', $clean['name']);
        }
        $count = count($this->all());
        if ($count >= self::MAX_PROMPTS) {
            throw new PromptError('too_many_prompts', self::MAX_PROMPTS);
        }
        $id = $this->db->query(
            'INSERT INTO bdus_mcp_prompts (name, title, description, arguments, template, sort) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $clean['name'], $clean['title'], $clean['description'],
                json_encode($clean['arguments'], JSON_UNESCAPED_UNICODE), $clean['template'],
                array_key_exists('sort', $data) ? (int) $data['sort'] : $count,
            ],
            'id'
        );
        return $this->get((int) $id);
    }

    /**
     * Replaces the content of a prompt (name, title, description, arguments,
     * template) and returns it; null when there is no such prompt.
     *
     * @throws PromptError  the data are not acceptable
     */
    public function update(int $id, array $data): ?array
    {
        if ($this->get($id) === null) {
            return null;
        }
        $clean = $this->validate($data);
        if ($this->nameTaken($clean['name'], $id)) {
            throw new PromptError('prompt_name_taken', $clean['name']);
        }
        $this->db->query(
            'UPDATE bdus_mcp_prompts SET name = ?, title = ?, description = ?, arguments = ?, template = ? WHERE id = ?',
            [
                $clean['name'], $clean['title'], $clean['description'],
                json_encode($clean['arguments'], JSON_UNESCAPED_UNICODE), $clean['template'], $id,
            ],
            'boolean'
        );
        return $this->get($id);
    }

    public function delete(int $id): bool
    {
        if ($this->get($id) === null) {
            return false;
        }
        $this->db->query('DELETE FROM bdus_mcp_prompts WHERE id = ?', [$id], 'boolean');
        return true;
    }

    /**
     * Sets the order: the ids listed come first, in that order; the others keep
     * their relative order after them. Unknown ids are ignored.
     *
     * @param int[] $ids
     */
    public function reorder(array $ids): void
    {
        $known = array_column($this->all(), 'id');
        $order = array_values(array_unique([
            ...array_filter(array_map('intval', $ids), fn($id) => in_array($id, $known, true)),
            ...$known,
        ]));
        foreach ($order as $pos => $id) {
            $this->db->query('UPDATE bdus_mcp_prompts SET sort = ? WHERE id = ?', [$pos, $id], 'boolean');
        }
    }

    private function nameTaken(string $name, ?int $exceptId): bool
    {
        foreach ($this->all() as $prompt) {
            if ($prompt['name'] === $name && $prompt['id'] !== $exceptId) {
                return true;
            }
        }
        return false;
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Checks and normalises what an administrator submits.
     *
     * @return array{name: string, title: string, description: string, arguments: array, template: string}
     * @throws PromptError
     */
    public function validate(array $data): array
    {
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if (preg_match(self::NAME_RE, $name) !== 1) {
            throw new PromptError('invalid_prompt_name', 'name');
        }

        $title       = $this->text($data, 'title', self::MAX_TITLE);
        $description = $this->text($data, 'description', self::MAX_DESCRIPTION);

        $template = is_string($data['template'] ?? null) ? str_replace("\r\n", "\n", $data['template']) : '';
        if (trim($template) === '' || mb_strlen($template) > self::MAX_TEMPLATE) {
            throw new PromptError('invalid_prompt_template', 'template');
        }

        $rawArgs = $data['arguments'] ?? [];
        if (!is_array($rawArgs) || !array_is_list($rawArgs) || count($rawArgs) > self::MAX_ARGUMENTS) {
            throw new PromptError('invalid_prompt_arguments', 'arguments');
        }
        $arguments = [];
        $seen      = [];
        foreach ($rawArgs as $i => $arg) {
            $argName = is_array($arg) && is_string($arg['name'] ?? null) ? trim($arg['name']) : '';
            if (preg_match(self::ARG_NAME_RE, $argName) !== 1 || isset($seen[$argName])) {
                throw new PromptError('invalid_prompt_arguments', "arguments[{$i}].name");
            }
            $seen[$argName] = true;
            $arguments[]    = [
                'name'        => $argName,
                'description' => $this->text($arg, 'description', self::MAX_ARG_DESC),
                'required'    => !empty($arg['required']),
            ];
        }

        // A placeholder nobody can fill would reach the model as literal braces.
        $undeclared = array_values(array_diff(self::placeholders($template), array_keys($seen)));
        if ($undeclared) {
            throw new PromptError('undeclared_prompt_placeholder', $undeclared);
        }

        return [
            'name'        => $name,
            'title'       => $title,
            'description' => $description,
            'arguments'   => $arguments,
            'template'    => $template,
        ];
    }

    private function text(array $data, string $key, int $max): string
    {
        $value = $data[$key] ?? '';
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $max) {
            throw new PromptError('invalid_prompt_' . ($key === 'title' ? 'title' : 'description'), $key);
        }
        return trim($value);
    }

    /** @return string[] the distinct placeholders of a template */
    public static function placeholders(string $template): array
    {
        preg_match_all(self::PLACEHOLDER_RE, $template, $m);
        return array_values(array_unique($m[1]));
    }

    // ── Render (prompts/get) ──────────────────────────────────────────────────

    /**
     * The text of a prompt for the arguments a client supplied. An optional
     * argument left out becomes an empty string.
     *
     * @param array<string, mixed> $args
     * @throws PromptError  a required argument is missing, an argument is not declared or not text
     */
    public function render(array $prompt, array $args): string
    {
        $declared = array_column($prompt['arguments'], null, 'name');

        foreach ($args as $name => $value) {
            if (!isset($declared[$name])) {
                throw new PromptError('unknown_prompt_argument', (string) $name);
            }
            if (!is_scalar($value) || mb_strlen((string) $value) > self::MAX_ARG_VALUE) {
                throw new PromptError('invalid_prompt_argument', (string) $name);
            }
        }
        $missing = [];
        foreach ($prompt['arguments'] as $arg) {
            if ($arg['required'] && trim((string) ($args[$arg['name']] ?? '')) === '') {
                $missing[] = $arg['name'];
            }
        }
        if ($missing) {
            throw new PromptError('missing_prompt_argument', $missing);
        }

        return preg_replace_callback(
            self::PLACEHOLDER_RE,
            fn($m) => (string) ($args[$m[1]] ?? ''),
            $prompt['template']
        );
    }
}
