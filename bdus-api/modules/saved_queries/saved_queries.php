<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 10, 2012
 */

class saved_queries_ctrl extends Controller
{
    public function getById()
    {
        $id = $this->get['id'];

        $savedQ = new SavedQueries($this->db);
        $res = $savedQ->getById($id);

        if ($res[0]) {
            echo json_encode([
                'status' => 'success', 
                'tb'=>$res[0]['tb'], 
                'text'=>urlencode(base64_encode($res[0]['text']))
            ]);
        } else {
            echo json_encode( [ 'status'=>'error' ] );
        }
    }

    public function showAll()
    {
        $savedQ = new SavedQueries($this->db);
        $res = $savedQ->getAll();

        foreach ($res as &$q) {
            $q['tb_label'] = cfg::tbEl($q['tb'], 'label');
            $q['encoded'] = urlencode(base64_encode($q['text']));
            $q['owned_by_me'] = $_SESSION['user']['id'] === $q['user_id'];
        }

        $this->render('saved_queries', 'showAll', [
            'saved_queries' => $res
        ]);
    }


    public function shareQuery()
    {
        try {
            $id = $this->get['id'];
            $savedQ = new SavedQueries($this->db);

            $msg = [
                'status'=>'error', 
                'text'=>tr::get('error_sharing_query')
            ];

            if ($savedQ->share($id)) {
                $msg = [
                    'status'=>'success', 
                    'text'=>tr::get('ok_sharing_query')
                ];
            }
            
        } catch (myException $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

    public function unShareQuery()
    {
        try {
            $id = $this->get['id'];
            $savedQ = new SavedQueries($this->db);

            $msg = [
                'status'=>'error', 
                'text'=>tr::get('error_unsharing_query')
            ];

            if ($savedQ->unshare($id)) {
                $msg = [
                    'status'=>'success', 
                    'text'=>tr::get('ok_unsharing_query')
                ];
            }
            
        } catch (myException $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

    public function deleteQuery()
    {
        try {
            $id = $this->get['id'];
            $savedQ = new SavedQueries($this->db);

            $msg = [
                'status'=>'error', 
                'text'=>tr::get('error_erasing_query')
            ];

            if ($savedQ->erase($id)) {
                $msg = [
                    'status'=>'success', 
                    'text'=>tr::get('ok_erasing_query')
                ];
            }
            
        } catch (myException $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

    public function saveQuery()
    {
        try {
            $tb = $this->get['tb'];
            $name = $this->get['name'];
            $query_text = $this->post['query_text'];

            $savedQ = new SavedQueries($this->db);

            $msg = [
                'status'=>'error', 
                'text'=>tr::get('error_saving_query')
            ];

            if ($savedQ->save($name, $query_text, $tb)) {
                $msg = [
                    'status'=>'success', 
                    'text'=>tr::get('ok_saving_query')
                ];
            }
            
        } catch (myException $e) {
            $this->log->error($e);
        }

        $this->returnJson($msg);
    }

}
