<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus\Controllers;

/**
 * MCP endpoint of the application: POST /{app}/api/mcp.
 *
 * The HTTP boundary only: reads the body and the headers, hands them to
 * \Mcp\Server and writes the answer. Unlike the rest of the API, answers use
 * HTTP status codes and JSON-RPC bodies (not the `status` / `code` envelope):
 * that is what MCP clients expect.
 *
 * Reached by any principal that may read (see Router::ROUTE_PRIVILEGE and
 * GROUP_KEY_ROUTES); each tool then checks tables and rows itself.
 */
class Mcp extends \Bdus\Controller
{
    public function handle(): void
    {
        $settings = \Config\AppSettings::getMcp($this->db);
        if (!$settings['enabled']) {
            $this->emit(404, ['jsonrpc' => '2.0', 'id' => null, 'error' => [
                'code'    => -32601,
                'message' => 'MCP is not enabled for this application.',
            ]]);
            return;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        if (!$this->originAllowed($headers)) {
            $this->emit(403, ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Origin not allowed.']]);
            return;
        }

        // Every change made from here on is recorded in the history as coming from MCP.
        \Auth\Channel::set('mcp');

        $server = new \Mcp\Server(new \Mcp\Context(
            $this->db,
            $this->cfg,
            $this->access(),
            $settings,
            $this->log,
            \JWT\JwtManager::deriveKey(APP, 'mcp-validation')
        ));
        [$status, $body] = $server->handle((string) file_get_contents('php://input'), $headers);
        $this->emit($status, $body);
    }

    /**
     * A browser names the page it calls from in `Origin`; other clients send
     * none. An origin is accepted when it is this host or one listed in
     * BRADYPUS_CORS_ORIGIN.
     */
    private function originAllowed(array $headers): bool
    {
        $origin = $headers['origin'] ?? '';
        if ($origin === '') {
            return true;
        }
        $allowed = array_filter(array_map('trim', explode(' ', (string) getenv('BRADYPUS_CORS_ORIGIN'))));
        if (in_array($origin, $allowed, true)) {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $self = $headers['x-forwarded-host'] ?? $headers['host'] ?? '';
        return $host !== null && in_array($self, [$host, $host . ':' . $port], true);
    }

    private function emit(int $status, ?array $body): void
    {
        http_response_code($status);
        if ($body === null) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
