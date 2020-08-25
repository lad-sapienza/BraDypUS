<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Jan 10, 2012
 */

class autoloader
{
    protected $libDir;
    protected $modDir;

    public function __construct(string $libDir, string $modDir)
    {
        $this->libDir = $libDir;
        $this->modDir = $modDir;

        spl_autoload_register(array($this, 'loader'));
    }

    private function loader($className)
    {
        if (class_exists($className)) {
            return true;
        }
        if (is_file($this->libDir . 'vendor/' . str_replace('\\', '/', $className) . '.php')) {

            require_once $this->libDir . 'vendor/' . str_replace('\\', '/', $className) . '.php';

        // https://stackoverflow.com/a/619725/586449
        } elseif (substr_compare($className, '_ctrl', -5, 5) === 0 ) {

            $mod = str_replace('_ctrl', null, $className);

            if (file_exists($this->modDir . $mod . DIRECTORY_SEPARATOR . $mod . '.php')) {
                require_once $this->modDir . $mod . DIRECTORY_SEPARATOR . $mod . '.php';
            }

        } else {

            if (file_exists($this->libDir . $className . '.inc')) {
                require_once $this->libDir . $className . '.inc';
            } elseif (file_exists($this->libDir . $className . '.php')) {
                require_once $this->libDir . $className . '.php';
            } elseif (file_exists($this->libDir . str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php')) {
                require_once $this->libDir . str_replace('\\', DIRECTORY_SEPARATOR, $className) . '.php';
            } elseif (file_exists($this->modDir . DIRECTORY_SEPARATOR . $className . DIRECTORY_SEPARATOR . $className . '.php')) {
                require_once $this->modDir . DIRECTORY_SEPARATOR . $className . DIRECTORY_SEPARATOR . $className . '.php';
            } elseif (file_exists($this->libDir . 'interfaces' . DIRECTORY_SEPARATOR . $className . '.inc')) {
                require_once $this->libDir . 'interfaces' . DIRECTORY_SEPARATOR . $className . '.inc';
            } elseif (file_exists($this->libDir . 'interfaces' . DIRECTORY_SEPARATOR . $className . '.php')) {
                require_once $this->libDir . 'interfaces' . DIRECTORY_SEPARATOR . $className . '.php';
            } else {
                return false;
            }
        }
    }
}
