<?php
/**
 * Manages javascript and css compression and minify actions
 *
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDyUS. Communicating Cultural Heritage, http://bradypus.net, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since				May 5, 2014
 */

class Compress
{
  /**
	 *
	 * Echoes reference to js files .
	 * If type is false the precompiled compact archive (1 minified file) will be requested
	 * If type['mini'] the compact archive (1 minified file) will be created
	 * If type['debug'] the single files will be requested
	 *
	 * @param array $files	array of js files (with .js extension) to be requested
	 * @param string type 	defines the way js will be requested. Can be: mini | debug | false
	 */
	public static function js( $files, $type = false )
	{
		if ( ($type['mini'] || !file_exists('./js/bdus.mini.js')) && is_dir('./js-sources'))
		{
      $str = "/*\n * BraDypUS javascripts minified archive includes different sources and licenses" .
        "\n * For details on external libraries (copyrights and licenses) please consult the Credits information" .
        "\n*/";

			foreach ($files as $file) {

				$file = ltrim($file);

				if ( file_exists( './js-sources/' . $file ) )
				{
					$str .= "\n". JSMin::minify ( file_get_contents ( './js-sources/' . $file ) );
				}
			}
      if (file_exists('./js/bdus.mini.js') && hash_file('sha256', './js/bdus.mini.js') !== hash('sha256', $str))
      {
        @unlink('./js/bdus.mini.js');
  			utils::write_in_file ( './js/bdus.mini.js', $str);
      }

      echo '<script language="JavaScript" type="text/JavaScript" ' .
        'src="./js/bdus.mini.js?sha256=' . hash_file('sha256', './js/bdus.mini.js') . '"></script>' . "\n";
		}
		else if ( $type['debug'] && is_dir('./js-sources'))
		{
			foreach ( $files as $file )
			{
				$file = ltrim($file);

				if ( file_exists( './js-sources/' . $file ) )
				{
					echo "\n  " .
            '<script language="JavaScript" type="text/JavaScript" ' .
              ' src="./js-sources/' . $file .'?sha256=?_' . hash_file('sha256', './js-sources/' . $file) . '"></script>';
				}
			}
      return;
		}
    else
    {
      echo '<script language="JavaScript" type="text/JavaScript" src="./js/bdus.mini.js?sha256' . hash_file('sha256', './js/bdus.mini.js') . '"></script>' . "\n";
    }
	}

	/**
	 *
	 * Echoes reference to css files and favicon in page.
	 * If type is false the precompiled compact archive (1 minified file) will be requested
	 * If type['mini'] the compact archive (1 minified file) will be created
	 * If type['debug'] the single files will be requested
	 *
	 * @param array $files	array of css files (with .css extension) to be requested
	 * @param string type 	defines the way js will be requested. Can be: mini | debug | false
	 */

	public static function css ( $files, $type )
	{
		if ( $type['mini'] && file_exists('./css-less/main.less'))
		{
			$str = "/*\n * BraDypUS css minified archive includes different sources and licenses" .
        "\n * For details on external libraries (copyrights and licenses) please consult the Credits information" .
        "\n*/\n";

      try
      {
        $opts = array(
          'compress'=>true
        );
        $parser = new Less_Parser($opts);
        $parser->parseFile( "./css-less/main.less");
        $css = $parser->getCss();
        if (hash_file('sha256', "./css/mini.css") !== hash('sha256', $css))
        {
          file_put_contents("./css/mini.css", $str . $css);
        }
      }
      catch (Exception $e)
      {
        error_log($e->getTraceAsString());
      }
		}
    echo '<link type="text/css" media="all" rel="stylesheet" href="./css/mini.css?sha256' . hash_file('sha256', './css/mini.css') . '" />';

		echo "\n  ".'<link rel="shortcut icon" href="./img/favicon.ico">';
	}
}

?>
