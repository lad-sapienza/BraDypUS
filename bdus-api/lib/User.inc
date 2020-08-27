<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			03/apr/2011
 * @uses			class.db.inc
 */

class User
{
    private $db;
    private $prefix;
    private $app;

    /**
     *
     * Starts database object
     * @param \DB\DB\DBInterface $db
     */
    public function __construct(\DB\DB\DBInterface $db)
    {
        $this->db = $db;

        $this->app = $this->db->getApp();
        $this->prefix = $this->app . PREFIX_DELIMITER;
    }

    /**
     * Returns unique user encoded string
     * @param array $user_array array of user data
     */
    public function getToken($user_array)
    {
        $string = $this->app
                . $user_array['id']
                . $user_array['name']
                . $user_array['email']
                . $user_array['password']
                . $user_array['privilge'];

        return substr(base64_encode($string), 5, 10);
    }

    /**
     *
     * Authenticates user using email & password
     * and sets SESSION variables
     * @param string $email
     * @param string $password
     * @param int $user_id
     * @throws myException
     */
    public function login(string $email = null, string $password = null, string $remember = null, int $user_id = null): bool
    {
        if (!$this->app) {
            throw new myException(tr::get('no_app_error'));
        }

        if (!$email && !$password && $remember) {
            if (cookieAuth::get()) {
                return true;
            }
        }

        if ($user_id) {
            $res = $this->getUser(array('id'=> $user_id ));
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($password)) {
            // get user data
            $res = $this->getUser(array('id'=> $user_id, 'email' => $email, 'password' => $this->encodePwd($password) ));
        } else {
            throw new myException(tr::get('email_password_needed'));
        }

        if ($res[0]) {
            // remove password from array
            unset($res[0]['password']);

            // Set user preferences
            if ( isset($res[0]['settings']) ) {
                $sett_arr = unserialize($res[0]['settings']);
                if (is_array($sett_arr) && !empty($sett_arr)){
                    foreach ($sett_arr as $key => $value) {
                        pref::set($key, $value);
                    }
                }
            }

            // remove settings from array
            unset($res[0]['settings']);

            // assign user data to session variable
            $_SESSION['user'] = $res[0];

            $_SESSION['app'] = $this->app;

            if ($remember && $remember !== 'false') {
                cookieAuth::set();
            }

            return true;
        } else {
            throw new myException(tr::get('login_data_not_valid'));
        }
    } // end of login

    /**
     *
     * destroys session array!
     */
    public function logout()
    {
        //save preferences to db
        pref::save2DB($this->db);

        // emtpy user tmp dir
        utils::emptyDir();

        // delete old session files
        self::deleteOldSessions();
        
        self::forceLogOut();
    }

    /**
     * Deletes session files older than 24h
     */
    private static function deleteOldSessions()
    {
        $maxlife = 24*60*60; // 24h
        $sessions = utils::dirContent(MAIN_DIR . 'sessions');

        if ($sessions && is_array($sessions)) {
            foreach ($sessions as $s) {
                $filetime = filectime(MAIN_DIR . 'sessions/' . $s);

                if ($filetime && $filetime < time() - $maxlife) {
                    @unlink(MAIN_DIR . 'sessions/' . $s);
                }
            }
        }
    }

    public static function forceLogOut()
    {
        cookieAuth::destroy();
        // destroy session array!
        $_SESSION = array();

        session_destroy();
    }

    /**
     *
     * Saves a new user in DATABASE
     * Returns true/false
     * @param string $name		full user's name & surname
     * @param string $email		user's email address (must not be a duplicate)
     * @param string $username	user's not encoded username
     * @param string $password	user's not encoded password
     * @param string $privilege	user's privilege, default: 40
     */
    public function insert($name, $email, $password, $privilege = false)
    {
        if (!$privilege) {
            $privilege = 40;
        }

        if ($this->isDuplicate($email)) {
            throw new myException(tr::get('email_present', ['<strong>' . $email . '</strong>']));
        }

        if (!$this->isValid($email)) {
            throw new myException(tr::get('email_not_valid', ['<strong>' . $email . '</strong>']));
        }

        $statement = "INSERT INTO " . $this->prefix . "users "
        . "(id, name, email, password, privilege) "
        . "VALUES "
        . "(NULL, :name, :email, :password, :privilege);";

        $values = array(
            ':name'		=> $name,
            ':email'	=> $email,
            ':password'	=> $this->encodePwd($password),
            ':privilege'=> $privilege
        );

        return $this->db->query($statement, $values, 'boolean');
    }// end of insert

    /**
     *
     * Updates a user profile with new name and/or email and/or password and/or privilege
     * Returns true/false
     * @param int $id			user id to update
     * @param string $name		new user full name & sruname
     * @param string $email		new user email
     * @param string $password	new user password (not encoded!)
     * @param array $privilege	new user privilege
     * @param string $settings	user settings
     * @throws myException
     */
    public function update($id, $name = false, $email = false, $password = false, $privilege = false, $settings = false)
    {
        if (!$name && !$email && !$password && !$privilege && !$settings) {
            throw new myException(tr::get('not_enough_data'));
        } elseif ($email and $this->isDuplicate($email, $id)) {
            throw new myException(tr::get('email_present', ['<strong>' . $email . '</strong>']));
        } elseif ($email && !$this->isValid($email)) {
            throw new myException(tr::get('email_not_valid', ['<strong>' . $email . '</strong>']));
        } else {
            $partial_arr =  array();

            if ($name && $name !== '') {
                $partial_arr[] = 'name = :name';
                $val[':name'] = $name;
            }

            if ($email && $email !== '') {
                $partial_arr[] = 'email = :email';
                $val[':email'] = $email;
            }

            if ($password && $password !== '') {
                $partial_arr[] = 'password = :password';
                $val[':password'] = $this->encodePwd($password);
            }

            if ($privilege && $privilege !== '') {
                $partial_arr[] = 'privilege = :privilege';
                $val[':privilege'] = $privilege;
            }

            if ($settings && $settings !== '') {
                $partial_arr[] = 'settings = :settings';
                $val[':settings'] = $settings;
            }

            $statement = "UPDATE {$this->prefix}users SET " . implode(', ', $partial_arr) . " WHERE id = :id";

            $val[':id'] = $id;

            $ret = $this->db->query($statement, $val, 'boolean');

            /*
             * if user to update is current user, and privilege is changed, update SESSION!
             */

            if ($id == $_SESSION['user']['id'] and ($privilege && $privilege != '')) {
                $_SESSION['user']['privilege'] = $privilege;
            }

            return $ret;
        }
    } // end of update

    /**
     *
     * Deletes a user from user table
     * Returns tre/false
     * @param int $id	ID of user to erase
     */
    public function delete($id)
    {
        return $this->db->query(
            "DELETE FROM " . $this->prefix . "users WHERE id = ?", 
            [ $id ], 
            'boolean'
        );
    } // end of delete

	/**
	 * Returns array of users by provilege
	 *
	 * @param int $priv		access privilege
	 * @param string|false $comparison
	 * @return array
	 */
    public function getUserByPriv($priv, $comparison = false)
    {
		// Default value for comparison is =
		if (!$comparison || !in_array($comparison, [
            '>', '<', '>=', '<=', '='
            ])) {
            $comparison = '=';
        }

        return $this->db->query(
            "SELECT * FROM {$this->prefix}users WHERE privilege {$comparison} ?", 
            [ utils::privilege($priv) ], 
            'read'
        );
    }
    /**
     *
     * Gets one or more users from database.
     * Conditions are given ad an array
     * @param mixed $arr	Array of conditions or (string) 'all' to retrieve all users
     */
    public function getUser($arr)
    {
        $query = "SELECT * FROM {$this->prefix}users WHERE ";
        $values_arr = [];

        if ($arr === 'all') {
            $query .= '1=1';
        } else {
            $partial_arr = [];

            foreach ([ 'id', 'name', 'email', 'password', 'privilege'] as $field) {
                if( $arr[$field] && !empty($arr[$field]) ) {
                    array_push($partial_arr, "{$field} = ?");
                    array_push($values_arr, $arr[$field]);
                }
            }
            $query .= implode(' AND ', $partial_arr);
        }
        return $this->db->query($query, $values_arr);
    }// end of getUser

    /**
     *
     * Checks in user table if email is present.
     * Returns true if present, false if not
     *
     * @param string $email	email to check for
     */
    public function isDuplicate($email, $id = false)
    {
        $values = [];
        $partial = [];

        array_push($partial, "email = ? ");
        $values = $email;
        if ($id) {
            array_push($partial, "id != ? ");
            $values = $id;
        }
        $res = $this->db->query(
            'SELECT count(*) as tot FROM ' . $this->prefix . 'users WHERE ' . implode(" AND ", $partial) . ' LIMIT 1 OFFSET 0', 
            $values
        );

        return ($res[0]['tot'] > 0);
    } // end of isDuplicate


    /**
     * Checks if email address is valid
     * @param string $email
     */
    public function isValid($email)
    {
        return preg_match('/^[a-zA-Z0-9_.-]+@[a-zA-Z0-9-]+.[a-zA-Z0-9-.]+$/', $email);
    }

    /**
     *
     * Returnes encoded password
     * @param string $password	original password
     */
    public function encodePwd($password)
    {
        return sha1($password);
    }
}
