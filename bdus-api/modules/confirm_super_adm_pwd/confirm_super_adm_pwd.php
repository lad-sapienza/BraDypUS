<?php
use DB\System\Manage;

class confirm_super_adm_pwd_ctrl extends Controller
{
    public function check_pwd()
    {
        if (!\utils::canUser('super_admin')){
            \utils::response('not_a_super_admin_user', 'error');
            return;
        }
        // Logged used is super admin. Let's check the password
        $pwd = $this->post['pwd'];
        $current_user_id = $_SESSION['user']['id'];

        $sys_manager = new Manage($this->db, $this->prefix);
        
        $me = $sys_manager->getBySQL('users', 'id = ? AND password = ? ', [
            $current_user_id,
            \utils::encodePwd($pwd)
        ]);
        

        if(!$me || !is_array($me)){
            \utils::response('invalid_pasword', 'error');
            return;
        } else {
            \utils::response('valid_pasword', 'success');
            return;
        }
    }
}