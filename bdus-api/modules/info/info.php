<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 17, 2012
 */

class info_ctrl extends Controller
{
	public function faq()
	{
    
    $this->render('info', 'faq', array(
				'date' => date('Y'),
				'libs' => $libs,
				'version' => version::current()
		));
	}
	
	public function getIP()
	{
        
		$ipRegEx="[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}";
		
		if (preg_match("/^win/i", PHP_OS ))
		{
      //windows systems
			$cmd = "ipconfig/all";
			exec($cmd, $msg);
			$msg = implode("\n", $msg);
			preg_match("/(.+)ipv4 address[\. ]+ : ({$ipRegEx})\(Preferred\)/i", $msg, $ip);
      if (empty($ip))
      {
        preg_match("/(.+)indirizzo ip[\. ]+ : ({$ipRegEx})/i", $msg, $ip);
      }
			$my_ip = $ip[2];
		}
		else if ( preg_match ( "/linux/i", PHP_OS ) )
		{
			//linux system
			$cmd = "/sbin/ifconfig";
			exec($cmd, $msg);
			$msg=implode("\n", $msg);
			preg_match("/inet addr:({$ipRegEx})/i", $msg, $ip);
			$my_ip = $ip[1];
		}
		else if ( strtolower( PHP_OS) == 'darwin' )
		{
			//http://blogostuff.blogspot.com/2005/08/fun-with-ifconfig-on-mac-os-x.html
			$cmd = 'ifconfig | grep "inet "';
			exec($cmd, $msg);

			$tmp_msg = $msg;
			
			foreach ( $tmp_msg as &$line)
			{
				if (preg_match('/127\.0\.0\.1/', $line))
				{
					$line = false;
				}
			}
			$msg = implode("\n", $msg);
			preg_match("/inet ({$ipRegEx})/i", implode($tmp_msg), $ip);
		
			$my_ip = $ip[1];
		}
		else
		{
			\utils::response(\tr::get('cannot_get_ip_for_system', [PHP_OS]), 'error', true);
		}
		
		if ($my_ip)
		{
			$r['status'] = 'success';
			$r['text'] = $my_ip;
			$r['more'] = $msg;
			$r['cmd'] = $cmd;
			
			echo json_encode($r);
		}
	}
	
	
	public function copyright()
	{
		// js
		$libs = array(
				array('name' => "jQuery", 'by' => "jQuery Foundation", "web"=>"http://jquery.com/", "lic"=>"MIT, GPL"),
				array('name' => 'php2js.js', 'web' => 'http://phpjs.org/', 'lic' => 'MIT'),
				array('name' => 'jquery-sortable.js', 'by' => ' Jonas von Andrian', 'web' => 'http://johnny.github.io/jquery-sortable/', 'lic' =>'Modified BSD License'),
				array('name' => 'bootstrap-datepicker.js', 'by' => 'Stefan Petre (Improvements by Andrew Rowls, by @eternicode, by Julian Bogdani)', 'web' => 'https://github.com/jbogdani/bootstrap-datepicker', 'lic' => 'Apache License v2.0'),
				array('name' => "DataTables", 'by' => "Allan Jardine", "web"=>"http://www.datatables.net/", "lic"=>"GPL v2 or a BSD style license"),
				array('name' => 'jQuery keyboard', 'by' => 'Jeremy Satterfield, modified by Rob Garrison (Mottie on github)', 'web' => 'https://github.com/Mottie/Keyboard', 'lic' => 'MIT'),
				array('name' => "fileuploader", 'by' => "Andrew Valums, Widen Enterprises", "web"=>"https://github.com/Widen/fine-uploader", "lic"=>"GNU GPL v3"),
				array('name' => 'Select2', 'by' => 'Igor Vaynberg', 'web' => 'https://github.com/ivaynberg/select2', 'lic' => 'Apache License v2.0'),
				array('name' => 'jQueryPrintElement', 'by' => 'Erik Zaadi', 'web' => 'https://github.com/erikzaadi/jQueryPlugins/tree/master/jQuery.printElement', 'lic' => 'Dual licensed: MIT and GPL'),
				array('name' => 'jqPlot', 'by' => 'Chris Leonello', 'web' => 'http://www.jqplot.com/', 'lic' => 'Dual license: MIT and GPL version 2'),
				array('name' => 'OpenLayers', 'by'=>'OpenLayers Contributors', 'web'=>'http://openlayers.org/', 'lic'=>'2-clause BSD License (FreeBSD)'),
				array('name' => 'D3', 'by' => 'Michael Bostock', 'web' => 'http://d3js.org/', 'lic' => 'BSD 3-Clause License'),
				array('name' => 'Dagre', 'by' => 'Chris Pettitt', 'web' => 'https://github.com/cpettitt/dagre', 'lic' => 'MIT License'),
				array('name' => 'Leflet', 'by' => 'Vladimir Agafonkin, CloudMade', 'web' => 'http://leafletjs.com/', 'lic' => 'BSD 2-Clause License'),
				
			// php
				array('name' => "Twig", 'by' => "Fabien Potencier", "web"=>"http://twig.sensiolabs.org/", "lic"=>"new BSD license"),
				array('name' => 'BigDump', 'by' => 'Alexey Ozerov, modified by Julian Bogdani', 'web' => 'http://www.ozerov.de/bigdump/', 'lic' => 'GNU General Public License v2 or any later version'),
				array('name' => "JSMin - PHP implementation of Douglas Crockford's JSMin", 'by' => "Ryan Grove", "web"=>"http://code.google.com/p/jsmin-php/", "lic"=>"MIT"),
				array('name' => "php-tail", 'by' => "Peeter Tomberg", "web"=>"http://code.google.com/p/php-tail/", "lic"=>"GNU GPL v3"),
        array('name' => "Gisconverter", 'by' => "symm", "web"=>"https://github.com/symm/gisconverter", "lic"=>"BSD License"),
				
			//other
				array('name' => "Twitter Bootstrap", 'by' => "@mdo & @fat, copyright: Twitter", "web"=>"http://twitter.github.com/bootstrap", "lic"=>"Apache License v2.0")
				
				
				//array('name' => '', 'by' => '', 'web' => '', 'lic' => ''),
				//array('name' => '', 'by' => '', 'web' => '', 'lic' => ''),
				//array('name' => '', 'by' => '', 'web' => '', 'lic' => ''),
				//array('name' => '', 'by' => '', 'web' => '', 'lic' => ''),
				//array('name' => '', 'by' => '', 'web' => '', 'lic' => ''),
		);
		
		
		
    $this->render('info', 'main', array(
      'date' => date('Y'),
      'libs' => $libs,
      'version' => version::current()
      ));
		
	}
}

?>

