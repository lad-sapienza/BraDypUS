<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Bdus;

use DB\DBInterface;
use Config\Config;
use Monolog\Logger;

abstract class Controller
{
    protected array  $get;
    protected array  $post;
    protected array  $request;
    protected ?DBInterface $db  = null;
    protected ?Logger      $log = null;
    protected ?Config      $cfg = null;

    public function __construct(array $get, array $post, array $request)
    {
        $this->get = $get;

        // Merge JSON body into $post so $this->post works transparently
        // for requests sent with Content-Type: application/json.
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $json = json_decode(file_get_contents('php://input'), true);
            $post = is_array($json) ? array_merge($post, $json) : $post;
        }

        $this->post    = $post;
        $this->request = array_merge($get, $post);
    }

    /** Access of the current principal (group rules, locks), built on first use. */
    private ?\Auth\Access $access = null;

    public function setDB(DBInterface $db): void   { $this->db  = $db;  }
    public function setCfg(Config $cfg): void       { $this->cfg = $cfg; }
    public function setLog(Logger $log): void       { $this->log = $log; }

    protected function access(): \Auth\Access
    {
        return $this->access ??= \Auth\Access::forCurrentUser($this->db, $this->cfg);
    }

    /**
     * SQL to append (with AND) to a query over $tb so it only sees the rows the
     * principal may read: [' AND (cond)', values], or ['', []] when nothing is withheld.
     * The condition refers to columns as `{$tb}.col`, so the query must use the
     * table name unaliased.
     *
     * @return array{0: string, 1: array}
     */
    protected function andReadable(string $tb): array
    {
        [$cond, $values] = $this->access()->rowCondition('read', $tb);
        return $cond === '1=1' ? ['', []] : [' AND (' . $cond . ')', $values];
    }

    /**
     * The record whose rules govern a write to record $id of $tb: the record
     * itself, or for a plugin table the host record its row belongs to (plugin
     * rows follow their record). [null, 0] when a plugin row has no resolvable host.
     *
     * @return array{0: ?string, 1: int}
     */
    protected function writeTarget(string $tb, int $id): array
    {
        if (!$this->cfg->get("tables.{$tb}.is_plugin")) {
            return [$tb, $id];
        }
        $host = $this->cfg->get("tables.{$tb}.plugin_of");
        if (!$host || !preg_match('/^[A-Za-z0-9_]+$/', $tb)) {
            return [null, 0];
        }
        $row    = $this->db->query("SELECT id_link FROM {$tb} WHERE id = ?", [$id], 'read');
        $idLink = (int) ($row[0]['id_link'] ?? 0);
        return $idLink ? [$host, $idLink] : [null, 0];
    }

    /**
     * May the principal change (`update` or `delete`) record $id of $tb? The
     * single gate for writes to existing records: the tier or a group rule must
     * grant it (table level, $creator lets a self writer act on their own
     * records), and the row must be inside the principal's scope and outside
     * every lock. A plugin row is judged by its host record, always as an update.
     */
    protected function mayChange(string $action, string $tb, int $id, ?int $creator = null): bool
    {
        if ($this->cfg->get("tables.{$tb}.is_plugin")) {
            $action = 'update';
        }
        [$gateTb, $gateId] = $this->writeTarget($tb, $id);
        if ($gateTb === null) {
            return false;
        }
        if (!\Auth\Authorization::can('edit', $creator, $gateTb)) {
            return false;
        }
        $access = $this->access();
        return $access->unrestricted($action, $gateTb) || $access->rowAllowed($action, $gateTb, $gateId);
    }

    /**
     * May the principal add something that belongs to record $id of $tb (a
     * geometry, say)? Principals whose tier already allows adding keep that, but
     * not on a frozen record; those relying on group rules need the right to
     * change the record.
     */
    protected function mayAttach(string $tb, int $id): bool
    {
        if (\Auth\Authorization::can('add_new')) {
            [$gateTb, $gateId] = $this->writeTarget($tb, $id);
            return $gateTb !== null && !$this->access()->isLocked($gateTb, $gateId);
        }
        return $this->mayChange('update', $tb, $id);
    }

    /** May the principal read record $id of $tb? (table access plus the row scope) */
    protected function mayReadRecord(string $tb, int $id): bool
    {
        if (!\Auth\Authorization::can('read', table: $tb)) {
            return false;
        }
        $access = $this->access();
        return $access->unrestricted('read', $tb) || $access->rowAllowed('read', $tb, $id);
    }

    /**
     * Emits a JSON response. Injects `"status": "success"` if not already set.
     *
     * Never emits an empty body. json_encode() returns false on malformed UTF-8
     * (common in text carried over from a legacy Latin-1 v4 database) and on
     * recursion / depth overflow; left unchecked, `echo false` produces a 200
     * with a zero-byte body and no log line, and the frontend then fails with
     * "JSON.parse: unexpected end of data". JSON_INVALID_UTF8_SUBSTITUTE keeps
     * the response usable by replacing bad byte sequences with U+FFFD; any other
     * encode failure is logged and returned as a parseable error envelope.
     */
    public function returnJson(array $data): void
    {
        if (!array_key_exists('status', $data)) {
            $data = ['status' => 'success'] + $data;
        }

        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $msg = json_last_error_msg();
            if ($this->log !== null) {
                $this->log->error('returnJson: json_encode failed: ' . $msg);
            } else {
                error_log('returnJson: json_encode failed: ' . $msg);
            }
            http_response_code(500);
            $json = json_encode([
                'status' => 'error',
                'code'   => 'json_encode_failed',
                'detail' => $msg,
            ]);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo $json;
    }
}
