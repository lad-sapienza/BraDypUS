<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 10, 2012
 */

use \DB\System\Manage;

class saved_queries_ctrl extends Controller
{
    public function getById()
    {
        $id = $this->get['id'];

        $sys_manager = new Manage($this->db, $this->prefix);
        $res = $sys_manager->getById('queries', $id);

        if (!empty($res)) {
            echo json_encode([
                'status' => 'success', 
                'tb'=>$res['tb'], 
                'obj_encoded'=> \SQL\SafeQuery::encode($res['text'], json_decode($res['vals'], true))
            ]);
        } else {
            echo json_encode( [ 'status'=>'error' ] );
        }
    }

    public function showAll()
    {
        $sys_manager = new Manage($this->db, $this->prefix);
        $res = $sys_manager->getBySQL('queries', "user_id = ? OR is_global = ?", [$_SESSION['user']['id'], 1]);

        foreach ($res as &$q) {
            $q['tb_label'] = $this->cfg->get("tables.{$q['tb']}.label");
            $q['obj_encoded'] = \SQL\SafeQuery::encode($q['text'], json_decode($q['vals']) ?: [] );
            $q['owned_by_me'] = $_SESSION['user']['id'] === $q['user_id'];
        }

        $this->render('saved_queries', 'showAll', [
            'saved_queries' => $res
        ]);
    }


    public function shareQuery()
    {
        $id = $this->get['id'];

        $msg = [
            'status' => 'error', 
            'text' => \tr::get('error_sharing_query')
        ];

        try {
            $sys_manager = new Manage($this->db, $this->prefix);
            $res = $sys_manager->editRow('queries', $id, ['is_global' => 1]);

            if ($res) {
                $msg = [
                    'status' => 'success', 
                    'text' => \tr::get('ok_sharing_query')
                ];
            }
            
        } catch (\DB\DBException $e) {
            // Already logged
        } catch (\Throwable $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

    public function unShareQuery()
    {
        $id = $this->get['id'];
        $msg = [
            'status' => 'error', 
            'text' => \tr::get('error_unsharing_query')
        ];

        try {
            $sys_manager = new Manage($this->db, $this->prefix);
            $res = $sys_manager->editRow('queries', $id, ['is_global' => 0]);

            if ($res) {
                $msg = [
                    'status' => 'success', 
                    'text' => \tr::get('ok_unsharing_query')
                ];
            }
            
        } catch (\DB\DBException $e) {
            // do nothing
        } catch (\Throwable $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

    public function deleteQuery()
    {
        $id = $this->get['id'];
        $msg = [
            'status' => 'error', 
            'text' => \tr::get('error_erasing_query')
        ];

        try {
            $sys_manager = new Manage($this->db, $this->prefix);
            $res = $sys_manager->deleteRow('queries', $id);

            if ($res) {
                $msg = [
                    'status' => 'success', 
                    'text' => \tr::get('ok_erasing_query')
                ];
            }
            
        } catch (\DB\DBException $e) {
        } catch (\Throwable $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

    public function saveQuery()
    {
        $tb = $this->get['tb'];
        $name = $this->get['name'];
        $query_object = $this->post['query_object'];
        $msg = [
            'status' => 'error', 
            'text' => \tr::get('error_saving_query')
        ];
        
        try {
            $sys_manager = new Manage($this->db, $this->prefix);
            list($text, $values) = \SQL\SafeQuery::decode($query_object);
            $res = $sys_manager->addRow('queries', [
                'user_id' => $_SESSION['user']['id'],
                'date'  => (new \DateTime())->format('Y-m-d H:i:s'),
                'name'  => $name,
                'text'  => $text,
                'vals'  => json_encode($values),
                'tb'    => $tb,
                'is_global'=> 0
            ]);

            if ($res) {
                $msg = [
                    'status' => 'success', 
                    'text' => \tr::get('ok_saving_query')
                ];
            }
            
        } catch (\DB\DBException $e) {
            // already logged
        } catch (\Throwable $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

}
