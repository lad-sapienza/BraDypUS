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
	private $db, $prefix;

	/**
	 *
	 * Starts database object
	 * @param DB $db
	 */
	public function __construct(DB $db)
	{
		$this->db = $db;

		$this->prefix = $this->db->getApp();
	}

	/**
	 * Returns unique user encoded string
	 * @param array $user_array array of user data
	 */
	public function getToken($user_array)
	{
		$string = $this->prefix
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
	public function login( $email = false, $password = false, $remember = false, $user_id = false )
	{
		if (!$this->prefix) {
			throw new myException(tr::get('no_app_error'));
		}

		if (!$email && !$password && $remember ) {

			if (cookieAuth::get()) {
        $this->userLog();
				return true;
			}
		}

		if ($user_id) {
			$res = $this->getUser( array ('id'=> $user_id ) );
		} else if ( filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($password) ) {
			// get user data
			$res = $this->getUser( array ('id'=> $user_id, 'email' => $email, 'password' => $this->encodePwd( $password ) ) );
		} else {
			throw new myException(tr::get('email_password_needed'));
		}

		if ( $res[0] ) {
			// remove password from array
			unset($res[0]['password']);

			// remove settings from array
			unset($res[0]['settings']);

			// assign user data to session variable
			$_SESSION['user'] = $res[0];

			$_SESSION['app'] = $this->prefix;

			$this->userLog();

			// load preferences from db
			pref::getFromDB($this->prefix);

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
		try {
			//save preferences to db
			pref::save2DB();

			// emtpy user tmp dir
			utils::emptyDir();

			// write logout text in log file
			self::userLog(true);

      // delete old session files
      self::deleteOldSessions();
		} catch (myException $e) {
			$e->log();
		}

		self::forceLogOut();
	}

  /**
   * Deletes session files older than 24h
   */
  private static function deleteOldSessions()
  {
    $maxlife = 24*60*60; // 24h
    $sessions = utils::dirContent(MAIN_DIR . 'sessions');

		if ( $sessions && is_array($sessions)) {

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
	 * Registers user login/out activity
	 * @param  boolean $out if true, user just logged out
	 */
	private function userLog(bool $out = false)
	{
		Meta::addUserLog($_SESSION['user']['id'], $out);
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
		if ( !$privilege ) {
			$privilege = 40;
		}

		if ( $this->isDuplicate( $email ) ) {
			throw new myException(tr::sget('email_present', '<strong>' . $email . '</strong>'));
		}

		if (!$this->isValid($email)) {
			throw new myException(tr::sget('email_not_valid', '<strong>' . $email . '</strong>'));
		}

		$statement = "INSERT INTO `" . $this->prefix . "__users` "
		. "(`id`, `name`, `email`, `password`, `privilege`) "
		. "VALUES "
		. "(NULL, :name, :email, :password, :privilege);";

		$values = array(
			':name'		=> $name,
			':email'	=> $email,
			':password'	=> $this->encodePwd( $password ),
			':privilege'=> $privilege
		);

		return $this->db->query( $statement, $values, 'boolean' );

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
	public function update ( $id, $name = false, $email = false, $password = false, $privilege = false, $settings = false )
	{
		if ( !$name && !$email && !$password && !$privilege && !$settings) {
			throw new myException(tr::get('not_enough_data'));

		} else if ( $email AND $this->isDuplicate( $email, $id ) ) {
			throw new myException(tr::sget('email_present', '<strong>' . $email . '</strong>'));

		} else if ($email && !$this->isValid($email)) {
			throw new myException(tr::sget('email_not_valid', '<strong>' . $email . '</strong>'));

		} else {

			$partial_arr =  array ();

			if ( $name && $name !== '' ) {
				$partial_arr[] = '`name` = :name';
				$val[':name'] = $name;
			}

			if ( $email && $email !== '' ) {
				$partial_arr[] = '`email` = :email';
				$val[':email'] = $email;
			}

			if ( $password && $password !== '' ) {
				$partial_arr[] = '`password` = :password';
				$val[':password'] = $this->encodePwd( $password );
			}

			if ( $privilege && $privilege !== '' ) {
				$partial_arr[] = '`privilege` = :privilege';
				$val[':privilege'] = $privilege;
			}

			if ( $settings && $settings !== '' ) {
				$partial_arr[] = '`settings` = :settings';
				$val[':settings'] = $settings;
			}

			$statement = 'UPDATE `' . $this->prefix . '__users` SET ' . implode(', ', $partial_arr ). ' WHERE `id` = :id';

			$val[':id'] = $id;

			$ret = $this->db->query( $statement, $val, 'boolean' );

			/*
			 * if user to update is current user, and privilege is changed, update SESSION!
			 */

			if ( $id == $_SESSION['user']['id'] AND ($privilege && $privilege != '') ) {
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
	public function delete ( $id )
	{
		$statement = "DELETE FROM `" . $this->prefix . "__users` WHERE `id` = {$id}";

		return $this->db->query ( $statement, false, 'boolean' );

	} // end of delete

	public function getUserByPriv($priv, $comparison = false)
	{
		switch($comparison) {
			case '>':
			case '<':
			case '>=':
			case '<=':
				case '=':
				continue;
				break;
			case false:
			default:
				$comparison = '=';
				break;
		}

		$query = 'SELECT * FROM `' . $this->prefix . '__users` WHERE `privilege` ' . $comparison . ' ' . utils::privilege($priv);

		return $this->db->query($query, false, 'read');
	}
	/**
	 *
	 * Gets one or more users from database.
	 * Conditions are given ad an array
	 * @param mixed $arr	Array of conditions or (string) 'all' to retrieve all users
	 */
	public function getUser( $arr )
	{
		$query = "SELECT * FROM `" . $this->prefix . "__users` WHERE ";

		if ( $arr === 'all' ) {
			$query .= '1';

		} else {

			$partial_arr = array ();

			($arr['id'] && $arr['id'] !== '') ? array_push( $partial_arr, "`id` = '{$arr['id']}'") : '';

			($arr['name'] && $arr['name'] !== '') ? array_push( $partial_arr, "`name` = '{$arr['name']}'") : '';

			($arr['email'] && $arr['email'] !== '') ? array_push( $partial_arr, "`email` = '{$arr['email']}'") : '';

			($arr['password'] && $arr['password'] !== '') ? array_push( $partial_arr, "`password` = '{$arr['password']}'") : '';

			($arr['privilege'] && $arr['privilege'] !== '') ? array_push( $partial_arr, "`privilege` = '{$arr['privilege']}'") : '';

			$query .= implode(' AND ', $partial_arr );
		}


		return $this->db->query( $query );

	}// end of getUser

	/**
	 *
	 * Checks in user table if email is present.
	 * Returns true if present, false if not
	 *
	 * @param string $email	email to check for
	 */
	public function isDuplicate( $email, $id = false )
	{
		$query = "SELECT count(*) as `tot` FROM `" . $this->prefix . "__users` WHERE `email` = '{$email}' " . ( $id ? " AND `id` != $id " : '' ) . " LIMIT 1";

		$res = $this->db->query( $query );

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
	private function encodePwd ( $password )
	{
		return sha1( $password );
	}

}
