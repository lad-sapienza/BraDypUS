<?php

/**
 * @copyright 2007-2025 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace Record;

use DB\DBInterface;
use Config\Config;
use Auth\Access;
use Auth\WriteGate;
use Monolog\Logger;

/**
 * Creates or updates one record, with its plugin rows, in a single call: the
 * whole write path behind `POST /api/record/{tb}` — access gate (tier, group
 * rules, row scope, locks), payload shape, enforced values, validation, then
 * Record\Edit / Record\Persist. It knows nothing of the HTTP request, so any
 * entry point can use it.
 *
 * save() answers with the envelope the API returns: `status`, `code`, and
 * `id` on success, `errors` / `detail` on failure.
 */
class Save
{
  private readonly WriteGate $gate;
  private readonly Validator $validator;

  public function __construct(
    private readonly DBInterface $db,
    private readonly Config $cfg,
    private readonly Access $access,
    private readonly ?Logger $log = null,
  ) {
    $this->gate      = new WriteGate($db, $cfg, $access);
    $this->validator = new Validator($db, $cfg);
  }

  /**
   * @param mixed    $tb    table name
   * @param int|null $id    record id; null to create
   * @param mixed    $core  { field: value } — only the fields to save
   * @param mixed    $plgs  { plugin_tb: [ { id, _delete, _isNew, fields: { fld: val } } ] }
   * @return array   { status, code, id?, errors?, detail? }
   */
  public function save(mixed $tb, ?int $id, mixed $core, mixed $plgs): array
  {
    $prepared = $this->prepare($tb, $id, $core, $plgs);
    if (isset($prepared['status'])) {
      return $prepared;
    }
    [$isPlugin, $reader, $core, $plgs] = $prepared;

    try {
      if ($id) {
        // ── UPDATE path: apply changes to the already-loaded record, persist ─
        $editor = new \Record\Edit($reader);

        if (!empty($core)) {
          $editor->setCore($core);
        }

        foreach ($plgs as $plgTb => $rows) {
          foreach ($rows as $row) {
            $rowId  = !empty($row['id']) ? (int)$row['id'] : null;
            $delete = !empty($row['_delete']);
            $isNew  = !empty($row['_isNew']);
            $fields = $row['fields'] ?? [];

            if ($delete && $rowId) {
              $editor->setPluginRow($plgTb, $rowId, []);
            } elseif ($isNew && !empty($fields)) {
              $fields = $this->maybeCalibrateRadiocarbon($plgTb, $fields);
              $editor->setPluginRow($plgTb, null, $fields);
            } elseif ($rowId && !empty($fields)) {
              $fields = $this->maybeCalibrateRadiocarbon($plgTb, $fields);
              $editor->setPluginRow($plgTb, $rowId, $fields);
            }
          }
        }

        $result = $editor->persist($this->db, $this->cfg);
        $newId  = $result['core']['id'] ?? $id;

      } else {
        // ── INSERT path: build model directly with _val markers ─────────
        // For new records we skip Read/Edit (nothing to compare against)
        // and hand-craft the model with _val markers for every submitted field.

        // Server-side system fields: always override client-supplied values.
        // `id` must never be supplied on insert (auto-assigned by DB).
        unset($core['id']);

        // `creator` must be the authenticated user's id — but only for real
        // tables. Plugin tables (is_plugin=1) have no `creator` column by
        // design (ownership is inherited from the host record via id_link),
        // so any client-supplied value is dropped instead.
        //
        // `creator` is a nullable FK to bdus_users(id) (ON DELETE SET NULL),
        // never a plain NOT NULL integer — 0 is never a valid user id, so a
        // falsy CurrentUser::id() (API key auth, which has no human identity
        // by design — see issue #56) must map to null, not 0, or the INSERT
        // violates the FK constraint on every engine.
        if ($isPlugin) {
          unset($core['creator']);
        } elseif (array_key_exists('creator', $core)) {
          $core['creator'] = \Auth\CurrentUser::id() ?: null;
        }

        $coreModel = [];
        foreach ($core as $fld => $val) {
          $coreModel[$fld] = ['name' => $fld, '_val' => $val];
        }

        // Ensure creator is always written for real tables, even if the
        // frontend omitted it entirely.
        if (!$isPlugin && !isset($coreModel['creator'])) {
          $coreModel['creator'] = ['name' => 'creator', '_val' => \Auth\CurrentUser::id() ?: null];
        }

        $pluginsModel = [];
        foreach ($plgs as $plgTb => $rows) {
          $pluginsModel[$plgTb] = ['data' => []];
          foreach ($rows as $row) {
            if (!empty($row['_delete'])) continue;       // nothing to delete on insert
            $fields = $row['fields'] ?? [];
            if (empty($fields)) continue;
            $fields = $this->maybeCalibrateRadiocarbon($plgTb, $fields);
            $newRow = [];
            foreach ($fields as $fld => $val) {
              $newRow[$fld] = ['name' => $fld, '_val' => $val];
            }
            $pluginsModel[$plgTb]['data'][] = $newRow;
          }
        }

        $model = [
          'metadata'    => [
            'tb_id'      => $tb,
            'rec_id'  => null,
            'tb_label' => $this->cfg->get("tables.{$tb}.label"),
          ],
          'core'        => $coreModel,
          'plugins'     => $pluginsModel,
          'manualLinks' => [],
          'files'       => [],
          'geodata'     => [],
          'rs'          => [],
        ];

        $result = \Record\Persist::all($model, $this->db, $this->cfg);
        $newId  = $result['core']['id'] ?? null;
      }

      return [
        'status' => 'success',
        'code'   => $id ? 'success_saved' : 'success_created',
        'id'     => $newId,
      ];

    } catch (\Throwable $e) {
      $this->log?->error($e);
      // A message raised on purpose by a trigger (SIGNAL / RAISE EXCEPTION) is
      // meant for the user; every other database error stays generic.
      if ($e instanceof \Record\ViewReadOnlyException) {
        return ['status' => 'error', 'code' => 'view_read_only'];
      }
      $rule = $e instanceof \DB\DBException ? $e->getRuleMessage() : null;
      if ($rule !== null) {
        return ['status' => 'error', 'code' => 'db_rule_violation', 'detail' => $rule];
      }
      return ['status' => 'error', 'code' => 'error_saved', 'detail' => $e->getMessage()];
    }
  }

  /**
   * Everything save() checks, without writing: may the principal do it, is the
   * payload well formed, are the values allowed and valid. A dry run.
   *
   * @return array  an error envelope like save(), or
   *   { status: 'success', code: 'valid', core, plugins, current } — `core` and
   *   `plugins` as they would be written (values a group rule fills in included),
   *   `current` the stored values of the fields in `core` (null for a new record)
   */
  public function check(mixed $tb, ?int $id, mixed $core, mixed $plgs): array
  {
    $prepared = $this->prepare($tb, $id, $core, $plgs);
    if (isset($prepared['status'])) {
      return $prepared;
    }
    [, $reader, $core, $plgs] = $prepared;

    $current = null;
    if ($reader) {
      $current = [];
      foreach (array_keys($core) as $fld) {
        $current[$fld] = $reader->getCore($fld, true);
      }
    }

    return ['status' => 'success', 'code' => 'valid', 'core' => $core, 'plugins' => $plgs, 'current' => $current];
  }

  /**
   * The checks shared by save() and check().
   *
   * @return array  an error envelope (has `status`), or [isPlugin, reader|null, core, plugins]
   */
  private function prepare(mixed $tb, ?int $id, mixed $core, mixed $plgs): array
  {
    if (!$tb || !is_string($tb) || !in_array($tb, $this->cfg->get('tables.*.name') ?: [], true)) {
      return ['status' => 'error', 'code' => 'parameter_missing'];
    }

    // A SQL view that cannot be written: say so, instead of the generic
    // "not enough privilege" the ability gate below would answer.
    if ($this->cfg->get("tables.{$tb}.is_view")
        && ($this->cfg->get("tables.{$tb}.view_writable") ?: 'none') === 'none') {
      return ['status' => 'error', 'code' => 'view_read_only'];
    }

    // Plugin tables (is_plugin=1) never have their own `creator` column by
    // design — ownership is inherited from the host record via id_link, not
    // stored locally. Resolved once here and reused below: the INSERT path
    // skips writing `creator` entirely, the UPDATE path resolves it through
    // the host table for the self_writer ownership check.
    $isPlugin = (bool) $this->cfg->get("tables.{$tb}.is_plugin");

    // Creating and updating a record carry different privilege thresholds:
    // add_new (self_writer included, ≤25) for new records, edit for existing
    // ones — where self_writer may only edit records they themselves created
    // (Authorization::can('edit', $creator) checks $creator against the
    // current user's id). The reader built here for the ownership check is
    // reused below in the UPDATE path so the record is only loaded once.
    $reader = null;
    if ($id) {
      $reader  = new \Record\Read($id, null, $tb, $this->db, $this->cfg);
      $creator = $this->gate->ownerCreator($tb, $reader, $isPlugin);

      // The one gate for changing an existing record: tier or group rule, row
      // scope and locks. The record's plugin rows, links, RS and geodata are
      // written by this same call, so they follow the verdict.
      if (!$this->gate->mayChange('update', $tb, $id, $creator)) {
        return ['status' => 'error', 'code' => 'not_enough_privilege'];
      }

      // An id that is not there cannot be updated (after the gate: whether a record
      // exists is not told to someone who could not read it).
      if (!$this->db->query("SELECT 1 FROM {$tb} WHERE id = ?", [$id], 'read')) {
        return ['status' => 'error', 'code' => 'record_not_found'];
      }
    } else {
      $mayCreate = $isPlugin
        ? $this->gate->mayAddPluginRow($tb, is_array($core) ? $core : [])
        : \Auth\Authorization::can('add_new', table: $tb);
      if (!$mayCreate) {
        return ['status' => 'error', 'code' => 'not_enough_privilege'];
      }
    }

    // The payload may only name fields of this table and plugin tables of this
    // table, with plain values: those names end up in SQL, and a value that is
    // an array would be stored as the text "Array". Before any write.
    $shape = $this->validator->payloadShapeErrors($tb, $id, $core, $plgs);
    if ($shape !== null) {
      return $shape;
    }

    // `id` and `creator` are the server's: on an update a client value is dropped, as it
    // already is on create. Otherwise whoever may edit a record could hand it to another
    // user, or forge its author.
    if ($id && is_array($core)) {
      unset($core['id'], $core['creator']);
    }

    // Group rules may pin the value of some fields (`enforce`, #51): refuse a value
    // outside the allowed ones; on create, fill in a field left empty. Runs before
    // validation so that the filled-in values are validated like any other.
    if (!$isPlugin && is_array($core)) {
      $enforced = $this->access->enforceValues($id ? 'update' : 'create', $tb, $core, $id);
      if ($enforced === null) {
        return ['status' => 'error', 'code' => 'value_not_allowed'];
      }
      $core = $enforced;
    }

    // Server-side validation — runs before any DB write
    $validationErrors = $this->validator->validatePayload($tb, $id, $core, $plgs);
    if (!empty($validationErrors)) {
      return [
        'status' => 'error',
        'code'   => 'validation_failed',
        'errors' => $validationErrors,
      ];
    }

    return [$isPlugin, $reader, $core, $plgs];
  }

  /**
   * If $plgTb is a radiocarbon-dating plugin table (tables.{plgTb}.radiocarbon
   * = true in config — set by Config::activateRadiocarbon()), recomputes
   * cal_1s_from/to and cal_2s_from/to from bp/bp_error server-side,
   * overwriting whatever the client may have sent: the calibrated range is
   * scientific derived data and must never be trusted from the request.
   *
   * @param array $fields { fld: value }
   * @return array $fields, with cal_* overwritten when bp/bp_error are present
   */
  private function maybeCalibrateRadiocarbon(string $plgTb, array $fields): array
  {
    if (!$this->cfg->get("tables.{$plgTb}.radiocarbon")) {
      return $fields;
    }

    $bp      = $fields['bp']       ?? null;
    $bpError = $fields['bp_error'] ?? null;
    if (!is_numeric($bp) || !is_numeric($bpError)) {
      return $fields;
    }

    try {
      $curve  = $fields['curve'] ?? 'intcal20';
      $result = \Radiocarbon\Calibrator::calibrate((int)$bp, (int)$bpError, $curve);
      $fields['cal_1s_from'] = $result['cal_1s'][0];
      $fields['cal_1s_to']   = $result['cal_1s'][1];
      $fields['cal_2s_from'] = $result['cal_2s'][0];
      $fields['cal_2s_to']   = $result['cal_2s'][1];
    } catch (\Throwable $e) {
      // Leave cal_* untouched (likely null) if the value can't be calibrated
      // (e.g. bp outside curve range) — the row itself is still saved.
      $this->log?->warning('Radiocarbon calibration failed: ' . $e->getMessage());
    }

    return $fields;
  }
}
