<?php

/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

namespace UAC;

use DB\DBInterface;

class UAC
{

  public const SUPERADM       = 1;
  public const ADM            = 10;
  public const UPDATE         = 20;
  public const DELETE         = 20;
  public const CREATE         = 25;
  public const READ           = 30;
  public const ENTER          = 39;

  public $available_actions = [
    'enter',            // < ENTER
    'read',             // <= READ
    'create',           // <= CREATE
    'update',           // <= UPDATE || <= CREATE && is own record
    'delete',           // <= UPDATE || <= CREATE && is own record
    'multiple_edit',    // <= UPDATE
    'admin',            // <= ADM
    'super_admin'       // <= SUPERADM || <= ADM && is offline
  ];

  private $ual; // User Access Level array [global: int, tb_a: int, tb_n: int, tb_a: [int, where], tb_b, [int, where] ]
  private $app_status;
  private $is_online;
  private $action;
  private $db;

  /**
   * Initializes class and sets:
   *   - appliation status
   *   - on/offline status
   *   - Database object
   *
   * @param string $app_status: on, off or frozen
   * @param boolean $is_online
   * @param DBInterface $db
   */
  public function __construct(
    string $app_status,
    bool $is_online = false,
    DBInterface $db
  ) {
    if (!in_array($app_status, ['on', 'off', 'frozen'])) {
      throw new \Exception("Unknown apps status: `$app_status`: must be `on`, `off` or `frozen`",);
    }
    $this->app_status = $app_status;
    $this->is_online = $is_online;
    $this->db = $db;
  }

  /**
   * Sets array of User Acces Level
   *
   * @param array $ual: list of access privileges, eg. ['global' => \UAC\UAC::ENTER, 'sitarc__siti' => [\UAC\UAC::DELETE, 'id < 2']]
   * @return UAC
   */
  public function setUAL(array $ual): UAC
  {
    $this->ual = $ual;
    return $this;
  }

  /**
   * Verifies if user can run $action on $onTable
   *
   * @param string $action actio to be verified. Must be on the $available_actions
   * @param string $onTable Table name against action is run
   * @param integer $onRecId
   * @param boolean $user_owns_record
   * @return boolean
   */
  public function can(
    string $action,
    string $onTable = null,
    int $onRecId = null,
    bool $user_owns_record = false
  ): bool {

    // $action must be a valid action
    if (!in_array($action, $this->available_actions)) {
      $this->log("Unknown action $action");
      return false;
    }

    // User Access Level must be set and global must be set
    if (!is_array($this->ual) || !isset($this->ual['global'])) {
      $this->log("User access level not set");
      return false;
    }

    // WAIT is not allowed to do anything
    if ($this->ual['global'] > $this::ENTER) {
      $this->log("User is disabled");
      return false;
    }

    // When application is off only superadmins can enter
    if ($this->app_status === 'off' && $this->ual > $this::SUPERADM) {
      $this->log("Application is off: only super admins are allowed");
      return false;
    }

    switch ($action) {
      case 'enter':
        // Enter is only a Global option, application must not be off. superadmins can enter in off applications
        return $this->can_enter();

      case 'read':
        return $this->can_read($onTable, $onRecId);

      case 'create':
        return $this->can_create($onTable);

      case 'update':
      case 'delete':
        return $this->can_update($onTable, $onRecId, $user_owns_record);

      case 'multiple_edit':
        return $this->can_multiple_edit($onTable);

      case 'admin':
        return ($this->app_status !== 'frozen' && $this->ual['global'] <= $this::ADM);
        break;

      case 'super_admin':
        return (($this->ual['global'] <= $this::ADM && !$this->is_online) || $this->ual['global'] <= $this::SUPERADM);
        break;
    }
    return false;
  }

  private function log(string $msg)
  {
    // do sth. with $msg
  }

  /**
   * Checks if user can enter:
   *   superadmins alwayes enter
   *   other users must have global privilege ENTER and application status must not be off
   *
   * @return boolean
   */
  private function can_enter(): bool
  {
    // superadmins always enter
    if ($this->ual['global'] <= $this::SUPERADM) {
      return true;
    }
    // Other users must have at least global ENTER and appliction must not be off
    return ($this->app_status !== 'off' && $this->ual['global'] <= $this::ENTER);
  }

  /**
   * Checks if user can read, by looking at:
   *   global setting
   *   table-based setting, and finally on
   *   table and id based setting
   *
   * @param string|null $onTable
   * @param integer|null $onRecId
   * @return boolean
   */
  private function can_read(?string $onTable, ?int $onRecId): bool
  {
    // Global READ (etc.)
    if ($this->ual['global'] <= $this::READ) {
      return true;

      // Table-based UAC is set
    } else if (
      $onTable &&
      array_key_exists($onTable, $this->ual) &&
      !is_array($this->ual[$onTable]) &&
      $this->ual[$onTable] <= $this::READ
    ) {
      return true;

      // Table & record based UAC is set
    } else if (
      $onTable &&
      $onRecId &&
      array_key_exists($onTable, $this->ual) &&
      is_array($this->ual[$onTable])
    ) {
      list($ual, $sql) = $this->ual[$onTable];
      $found = $this->recordInSubset($onTable, $onRecId, $sql);
      return $found && $ual <= $this::READ;
    }

    // Return false by default
    return false;
  }

  /**
   * Checks if user can add new records, by looking at:
   *   Applications status must be on
   *   global setting
   *   table-based setting
   *
   * @param string|null $onTable
   * @return boolean
   */
  private function can_create(?string $onTable): bool
  {
    // No one can add records on a fronzen/off application
    if ($this->app_status !== 'on') {
      $this->log("Application status is {$this->app_status}: no one can add new record");
      return false;

      // Global CREATE (etc.)
    } else if ($this->ual['global'] <= $this::CREATE) {
      return true;

      // Table-based UAC is set
    } else if (
      $onTable &&
      array_key_exists($onTable, $this->ual) &&
      !is_array($this->ual[$onTable]) &&
      $this->ual[$onTable] <= $this::CREATE
    ) {
      return true;
    }

    // Return false by default
    return false;
  }

  /**
   * Checks if user can edit record, by looking at:
   *   Applications status must be on
   *   global setting at least UPDATE
   *   global setting at least CREATE and ownership on record
   *   table-based setting at least UPDATE
   *   table-based at least CREATE and ownership on record
   *   query-based setting at least UPDATE
   *
   * @param string|null $onTable
   * @param integer|null $onRecId
   * @param boolean $user_owns_record
   * @return boolean
   */
  private function can_update(?string $onTable, ?int $onRecId, bool $user_owns_record = false): bool
  {
    // No one can update records on a fronzen/off application
    if ($this->app_status !== 'on') {
      $this->log("Application status is {$this->app_status}: no one can edit records");
      return false;

      // Global UPDATE (etc.)
    } else if ($this->ual['global'] <= $this::UPDATE) {
      return true;

      // Global CREATE && is own record
    } else if ($this->ual['global'] <= $this::CREATE && $user_owns_record) {
      return true;

      // Table & Query based access
    } else if ($onTable && array_key_exists($onTable, $this->ual)) {

      // Query (subset) based UPDATE
      if (is_array($this->ual[$onTable]) && $onRecId) {
        list($ual, $sql) = $this->ual[$onTable];
        $found = $this->recordInSubset($onTable, $onRecId, $sql);
        return $found && $ual <= $this::UPDATE;

        // (Whole) Table based UPDATE
      } else if (!is_array($this->ual[$onTable])) {
        // UPDATE
        if ($this->ual[$onTable] <= $this::UPDATE) {
          return true;
          // CREATE & is own record
        } else if ($this->ual[$onTable] <= $this::CREATE && $user_owns_record) {
          return true;
        }
      }
    }

    // Return false by default
    return false;
  }

  /**
   * Checks if a user can edit multiple records, by looking at:
   *   Applications status must be on
   *   global setting at least UPDATE
   *   table-based setting at least UPDATE
   *
   * @param string|null $onTable
   * @return boolean
   */
  private function can_multiple_edit(?string $onTable): bool
  {
    // No one can update records on a fronzen/off application
    if ($this->app_status !== 'on') {
      $this->log("Application status is {$this->app_status}: no one can edit records");
      return false;

      // Global UPDATE (etc.)
    } else if ($this->ual['global'] <= $this::UPDATE) {
      return true;

      // Table-based UPDATE (etc.)
    } else if ($onTable && array_key_exists($onTable, $this->ual) && !is_array($this->ual[$onTable])) {
      return $this->ual[$onTable] <= $this::UPDATE;
    }

    // Return false by default
    return false;
  }

  /**
   * Checks if $rec_id of $tb is part of subset defined by $sql
   *
   * @param string $tb
   * @param integer $rec_id
   * @param string $sql
   * @return boolean
   */
  private function recordInSubset(string $tb, int $rec_id, string $sql): bool
  {
    $res = $this->db->query("SELECT count(*) as tot FROM $tb WHERE ($sql) AND id = $rec_id");
    return $res[0]['tot'] > 0;
  }
}
