<?php

namespace Bdus\Controllers;

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

 use DB\System\Manage;

class ConfirmAdminPwd extends \Bdus\Controller
{
    public function check_pwd()
    {
        if (!\Auth\Authorization::can('super_admin')){
            $this->returnJson(['status' => 'error', 'code' => 'not_a_super_admin_user']);
            return;
        }
        // Logged used is super admin. Let's check the password
        $pwd = $this->post['pwd'];
        $current_user_id = \Auth\CurrentUser::id();

        $sys_manager = new Manage($this->db);

        $me = $sys_manager->getById('bdus_users', $current_user_id);

        if (!$me || !\Auth\Password::verify($pwd, $me['password'])) {
            $this->returnJson(['status' => 'error', 'code' => 'invalid_pasword']);
            return;
        } else {
            $this->returnJson(['status' => 'success', 'code' => 'valid_pasword']);
            return;
        }
    }
}