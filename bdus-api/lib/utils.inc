<?php

/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Apr 7, 2012
 */

use \geoPHP\geoPHP;

class utils
{

  /**
   * A map of privileges: translates privilege code to string and string to code.
   * If $input is false will be set to the logged user privilege code.
   * If $input is 'all', the full array (int => string_key) is returned.
   * If $input is a string key, the corresponding integer code is returned.
   * If $input is an integer code, the corresponding string key is returned.
   *
   * @param mixed $input initial privilege
   * @param mixed $translate ignored (kept for BC, no longer used)
   */
  public static function privilege($input = false, $translate = false)
  {
    if (!$input) {
      $input = \Auth\CurrentUser::privilege();
    }

    $privilege_array = array(
      1  => 'super_admin',
      10 => 'admin',
      20 => 'writer',
      25 => 'self_writer',
      30 => 'reader',
      40 => 'waiting'
    );

    if ($input === 'all') {
      return $privilege_array;
    }

    if (in_array($input, $privilege_array)) {
      return array_search($input, $privilege_array);
    }

    if (in_array($input, array_keys($privilege_array))) {
      return $privilege_array[$input];
    }

    return false;
  }


  /**
   * Checks if user has a certail privilege. Returns true or false
   * TODO: move in general proviliege/user class
   *
   * @param string $privilege privilege (verbose) to check. One of: enter|read|review|add_new|multiple_edit|admin|super_admin
   * @param integer $creator row creator (self-editor)
   * @return boolean
   */
  public static function canUser(string $privilege = null, int $creator = null)
  {
    if (defined('PROJ_DIR')) {
      $app_data = json_decode(file_get_contents(PROJ_DIR . 'cfg/app_data.json'), true);
      $app_status = $app_data['status'];
    } else {
      $app_status = 'on';
    }

    $user_priv = \Auth\CurrentUser::privilege();


    if (!$privilege) {
      $privilege = 'edit';
    }

    if (!$user_priv || $user_priv === 0) {
      return false;
    }

    switch ($privilege) {
      case 'enter':
        return ($app_status !== 'off' && $user_priv < 39);
        break;

      case 'read':
      case 'preview':
        // read & preview can be global or table oriented
        return ($app_status !== 'off' && $user_priv < 31);
        break;


      case 'add_new':
        return ($app_status !== 'frozen' && $user_priv < 26);
        break;

      case 'edit':
        return $app_status !== 'frozen' && ($user_priv < 21 || ($creator && $creator == \Auth\CurrentUser::id() && $user_priv < 26));
        break;

      case 'multiple_edit':
        return ($app_status !== 'frozen' && $user_priv < 21);
        break;

      case 'admin':
        return ($app_status !== 'frozen' && $user_priv < 11);
        break;

      case 'super_admin':
        return ($user_priv < 2);
        break;

      default:
        return false;
        break;
    }
  }

  /**
   *
   * Returns an array with a list of files contained in $dir
   * File/directories: `.`, `..`, `.DS_Store`, `undefined`, `.svn`, `.git` are ignored
   * Returns false on error
   * 
   * @param string $dir	path to dir
   */
  public static function dirContent(string $dir)
  {
    $dont_consider = [
      '.', '..', '.DS_Store', 'undefined', '.svn', '.git'
    ];
    if (!is_dir($dir)) {
      return false;
    }
    $ret = array_diff(scandir($dir), $dont_consider);

    return empty($ret) ? false : $ret;
  }


  /**
   *
   * Empties a directory recursively.
   * By default (no dir specified) the user temporary folder will be emptied!
   * @param string $dir	directory to empty
   * @param string $delete	if true directory will be deleted
   * @throws \Exception
   */
  public static function emptyDir(string $dir, bool $delete_dir = false): bool
  {
    $files_in_tmp = self::dirContent($dir) ?: [];

    foreach ($files_in_tmp as $file) {
      if (is_dir($dir . '/' . $file)) {
        self::emptyDir($dir . '/' . $file, true);
      } else {
        if (!@unlink($dir . '/' . $file)) {
          throw new \Exception("Cannot delete file: {$file}");
        }
      }
    }

    if ($delete_dir && !@rmdir($dir)) {
      throw new \Exception("Cannot delete directory: {$dir}");
    }

    return true;
  }

  /**
   *
   * Returns an array of given string, using givel delimiter
   * @param string $string
   * @param string $separator
   */
  public static function csv_explode(string $string, string $delimiter = ','): array
  {
    return array_filter(array_map('trim', explode($delimiter, $string)), 'strlen');
  }

  /**
   * Recursively filters array using an optional callback function
   *  If no callback function is defined the resulting array will contain only
   *  not null/empty values of the original array. Key-value association is mantained
   *  Array values are trimmed
   * 
   * @param array $arr  Original array to filter
   * @param callable|null $callback Callback function, default false. Can be a function or a function name
   * @return array  Filtered array
   */
  public static function recursiveFilter(array $arr, ?callable $callback = null): array
  {
    foreach ($arr as &$a) {
      if (is_array($a)) {
        $a = self::recursiveFilter($a, $callback);
      } else {
        $a = trim($a);
      }
    }
    if (is_callable($callback)) {
      return array_filter($arr, $callback);
    }
    return array_filter($arr);
  }



  /**
   * Converts a multidimensional array to GeoJSON
   *
   * @param string $tb	Table name
   * @param array $rows	Array or database data
   * @return array		Valid geoJSON as array
   */
  public static function multiArray2GeoJSON(string $tb, array $rows): array
  {
    $geo = [
      'type' => 'FeatureCollection',
      'features' => []
    ];


    if (!is_array($rows)) {
      throw new Exception('Input data is not an array');
    }

    foreach ($rows as $r) {
      $arr = [];
      if (!is_array($r)) {
        throw new Exception('Input data is not a multidimensional array');
      }

      $geom = $r['geometry'] ?: $r[$tb . '.geometry'];

      if (!$geom) {
        // single row error does not block entire process
        error_log('No valid geometry column found in row: ' . var_export($r, true));
        continue;
      }
      try {
        $geoPHP = geoPHP::load($geom, 'wkt');
      } catch (\Throwable $th) {
        error_log("WKT geometry {$geom} could not be parsed: " . var_export($r, true));
        continue;
      }

      $arr['type']    = 'Feature';
      $arr['geometry']  = json_decode($geoPHP->out('geojson'), true);

      unset($r['geometry']);
      if ($r) {
        $arr['properties']  = $r;
      }

      array_push($geo['features'], $arr);
    }

    return $geo;
  }

  public static function encodePwd(string $password): string
  {
    return password_hash($password, PASSWORD_DEFAULT);
  }

  public static function verifyPassword(string $plain, string $stored): bool
  {
    if (strlen($stored) === 40) {
      return $stored === sha1($plain);
    }
    return password_verify($plain, $stored);
  }

  public static function isDuplicateEmail(\DB\DB $db, string $email = '', int $id = null): bool
  {
    $manager = new \DB\System\Manage($db);

    $partial = ["email = ? "];
    $values = [$email];


    if ($id) {
      array_push($partial, "id != ? ");
      array_push($values, $id);
    }
    $res = $manager->getBySQL('bdus_users', implode(" AND ", $partial) . ' LIMIT 1 OFFSET 0', $values, ['count(*) as tot']);

    return ($res[0]['tot'] > 0);
  }

  public static function debug($d, bool $echo = false): void
  {
    $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($echo) {
      echo "<pre>$json</pre>";
    } else {
      error_log('DEBUG: ' . $json);
    }
  }
}
