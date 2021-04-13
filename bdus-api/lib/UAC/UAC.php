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

    private $ual; // User Access Level array [g: int, tb_a: int, tb_n: int, tb_a: [int, where], tb_b, [int, where] ]
    private $app_status;
    private $is_online;
    private $onRecId;
    private $user_owns_record;
    private $action;
    private $onTable;
    private $db;

    public function __construct(string $app_status, bool $is_online = false, DBInterface $db)
    {
        $this->app_status = $app_status;
        $this->is_online = $is_online;
        $this->db = $db;
    }

    public function user(array $ual)
    {
        $this->ual = $ual;
        return $this;
    }

    public function can(string $action, string $onTable = null, int $onRecId = null, bool $user_owns_record = false) : bool
    {
        $this->action = $action;
        $this->onTable = $onTable;
        $this->onRecId = $onRecId;
        $this->user_owns_record = $user_owns_record;

        return $this->checkAll();
    }
    
    private function log( string $msg )
    {
        // do sth. witm $msg
    }

    
    private function checkAll() : bool
    {
        if (!is_array($this->ual)){
            $this->log("User access level not set");
            return false;
        }
        // WAIT is not allowed to do anything
        if ( $this->ual['global'] > $this::ENTER ) {
            $this->log("User is disabled");
            return false;
        }
        // When application is of only superadmins can enter
        if ($this->app_status === 'off' && $this->ual > $this::SUPERADM){
            $this->log("Application is off: only super admins are allowed");
            return false;
        }

        switch ($this->action) {
            case 'enter':
                // Enter is only a Global option
				return $this->ual['global'] <= $this::ENTER;

            case 'read':
                return $this->can_read();

            case 'create':
                return $this->can_create();

            case 'update':
            case 'delete':
                return $this->can_update();

            case 'multiple_edit':
                return $this->can_multiple_edit();

            case 'admin':
                return ($this->app_status !== 'frozen' && $this->ual['global'] <= $this::ADM);
				break;

            case 'super_admin':
                return ( ( $this->ual['global'] <= $this::ADM && !$this->is_online) || $this->ual['global'] <= $this::SUPERADM);
				break;
        }
        return false;
    }

    private function can_read() : bool
    {   
        // Global is set
        if ($this->ual['global'] <= $this::READ){
            return true;
        } else if ( 
            $this->onTable && 
            array_key_exists($this->onTable, $this->ual) && 
            !is_array($this->ual[$this->onTable]) &&
            $this->ual[$this->onTable] <= $this::READ
        ) {
            return true;
        } else if ( 
            $this->onTable && 
            $this->onRecId && 
            array_key_exists($this->onTable, $this->ual) && 
            is_array($this->ual[$this->onTable]) 
        ) {
            list($ual, $sql) = $this->ual[$this->onTable];
            $found = $this->recordInSubset($this->onTable, $this->onRecId, $sql);
            return $found && $ual <= $this::READ;
        }
        return false;
    }

    private function can_create() : bool
    {
        if ( $this->app_status === 'frozen' ){
            $this->log("Application is frozen: no one can add new record");
            return false;
        } else if ($this->ual['global'] <= $this::CREATE) {
            return true;
        } else if ( 
            // table is set and is set whole table privilege
            $this->onTable && 
            array_key_exists($this->onTable, $this->ual) && 
            !is_array($this->ual[$this->onTable]) &&
            $this->ual[$this->onTable] <= $this::CREATE
        ) {
            return true;
        } else if ( 
            // table is set and table privilege are set to edit on a subset
            $this->onTable && 
            array_key_exists($this->onTable, $this->ual) && 
            is_array($this->ual[$this->onTable])
        ) {
            list($ual, $sql) = $this->ual[$this->onTable];
            return $ual <= $this::UPDATE;
            return true;
        }
        return false;
    }

    private function can_update() : bool
    {
        if ($this->app_status === 'frozen') {
            $this->log("Application is frozen: no one can uddate or delete records");
            return false;

        // Global WRITE
        } else if ( $this->ual['global'] <= $this::UPDATE ) {
            return true;

        // Global INSERT && is own record
        } else if ( $this->ual['global'] <= $this::CREATE && $this->user_owns_record ){
            return true;

        // Table context
        } else if ( $this->onTable && array_key_exists($this->onTable, $this->ual) ) {
            
            // Subset
            if ( is_array($this->ual[$this->onTable]) && $this->onRecId) {
                list($ual, $sql) = $this->ual[$this->onTable];
                $found = $this->recordInSubset($this->onTable, $this->onRecId, $sql);
                return $found && $ual <= $this::UPDATE;

            // whole table
            } else if ( !is_array($this->ual[$this->onTable]) ) {
                // WRITE
                if ($this->ual[$this->onTable] <= $this::UPDATE) {
                    return true;
                // INSERT & own record
                } else if ($this->ual[$this->onTable] <= $this::CREATE && $this->user_owns_record ) {
                    return true;
                }
            }
        }
        return false;
    }

    private function can_multiple_edit() : bool
    {
        if ($this->app_status === 'frozen') {
            $this->log("Application is frozen: no one can add new record");
            return false;
        } else if  ($this->ual['global'] <= $this::UPDATE) {
            return true;
        } else if ($this->onTable && array_key_exists($this->onTable, $this->ual) && !is_array($this->ual[$this->onTable]) ) {
            return $this->ual[$this->onTable] <= $this::UPDATE;
        }
        return false;
    }

    private function recordInSubset(string $tb, int $rec_id, string $sql)
    {
        $res = $this->db->query("SELECT count(*) as tot FROM $tb WHERE ($sql) AND id = $rec_id");
        return $res[0]['tot'] > 0;
        
    }
}