<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

 use DB\System\Manage;

class confirm_super_adm_pwd_ctrl extends Controller
{
    public function check_pwd()
    {
        if (!\utils::canUser('super_admin')){
            $this->response('not_a_super_admin_user', 'error');
            return;
        }
        // Logged used is super admin. Let's check the password
        $pwd = $this->post['pwd'];
        $current_user_id = $_SESSION['user']['id'];

        $sys_manager = new Manage($this->db, $this->prefix);
        
        $me = $sys_manager->getById('users', $current_user_id);

        if (!$me || !\utils::verifyPassword($pwd, $me['password'])) {
            $this->response('invalid_pasword', 'error');
            return;
        } else {
            $this->response('valid_pasword', 'success');
            return;
        }
    }
}