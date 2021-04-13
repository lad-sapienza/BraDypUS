<?php
/**
* @copyright 2007-2021 Julian Bogdani
* @license AGPL-3.0; see LICENSE
* @since			Apr 17, 2012
*/

use Michelf\Markdown;

class info_ctrl extends Controller
{
    public function getIP()
    {
        
        $ipRegEx="[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}";
        
        if (preg_match("/^win/i", PHP_OS )) {
            //windows systems
            $cmd = "ipconfig/all";
            exec($cmd, $msg);
            $msg = implode("\n", $msg);
            preg_match("/(.+)ipv4 address[\. ]+ : ({$ipRegEx})\(Preferred\)/i", $msg, $ip);
            
            if (empty($ip)) {
                preg_match("/(.+)indirizzo ip[\. ]+ : ({$ipRegEx})/i", $msg, $ip);
            }
            
            $my_ip = $ip[2];
            
        } else if ( preg_match ( "/linux/i", PHP_OS ) ) {
            //linux system
            $cmd = "/sbin/ifconfig";
            exec($cmd, $msg);
            $msg=implode("\n", $msg);
            preg_match("/inet addr:({$ipRegEx})/i", $msg, $ip);
            $my_ip = $ip[1];
            
        } else if ( strtolower( PHP_OS) == 'darwin' ) {
            
            //http://blogostuff.blogspot.com/2005/08/fun-with-ifconfig-on-mac-os-x.html
            $cmd = 'ifconfig | grep "inet "';
            exec($cmd, $msg);
            
            $tmp_msg = $msg;
            
            foreach ( $tmp_msg as &$line) {
                if (preg_match('/127\.0\.0\.1/', $line)) {
                    $line = false;
                }
            }
            $msg = implode("\n", $msg);
            preg_match("/inet ({$ipRegEx})/i", implode($tmp_msg), $ip);
            
            $my_ip = $ip[1];
        } else {
            $this->response('cannot_get_ip_for_system', 'error', [PHP_OS]);
        }
        
        if ($my_ip) {
            $this->response($my_ip, 'success', null, [
                'more' => $msg,
                'cmd' => $cmd,
                ]);
            }
        }
        
        
        public function copyright()
        {
            $this->render('info', 'main', array(
                'date' => date('Y'),
                'changelog' => Markdown::defaultTransform(file_get_contents('CHANGELOG.md')),
                'version' => version::current()
            ));
            
        }
    }
    
    ?>
    
    