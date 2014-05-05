<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 6, 2012
 */

ob_start();

try
{
	$basePath = './';
	@$_REQUEST['debug'] ? $__go_debug = true : $__stop_debug = true;
	
	require_once './lib/constants.inc';
	
	if ($_GET['logout'])
	{
		try
		{
			$user = new User(new DB());
			$user->logout();
		}
		catch (myException $e)
		{
			User::forceLogOut();
		}
	}
	
	if (defined('PROJ_CFG_APPDATA'))
	{
		cfg::load(false, true);
	}
	if ($_GET['mini'])
	{
		utils::compressModScripts();
	}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>BraDypUS <?php echo version::current() . ($_SESSION['app'] ?  ' :: ' . strtoupper(cfg::main('name')) : '' ); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <?php
  /*
   * Styles loader
   */
  utils::css( array (
    'main.css'
  ), $_GET);

  ?>

  <script src="./controller.php?obj=tr&method=lang2json&param=true"></script>
  <script> var debugMode = <?php echo  ($_GET['debug'] ? 'true' : 'false');?></script>
  <?php
  utils::js( array(
    'php2js.js',
    'jquery-2.1.1.min.js',
    'jquery-sortable.js',
    'bootstrap.js',
    'bootstrap-datepicker.js',
    'jquery.dataTables.js',
    'datatables-bootstrap.js',
    'jquery.keyboard.js',
    'utils.js',
    'core.js',
    'jquery.pnotify.js',
    'api.js',
    'layout.js',
    'jquery.fineuploader-3.4.0.js',
    'formControls.js',
    'select2.js',
    'enhanceForm.js',
    'jquery.checklabel.js',
    'jquery.printElement.js',
    'jquery.jqplot.js',
    'jqplot.barRenderer.min.js',
    'jqplot.categoryAxisRenderer.min.js',
    'jqplot.pointLabels.js',
    'export-jqplot-to-png.js',
    'jquery.insertAtCaret.js',
    'bootstrap-slider.js'

  ), $_GET );
  ?>
  <script>
    $(document).ready(function(){
      layout.init();
<?php if ( utils::canUser('enter') ): ?>
      layout.loadHome();
      layout.hashActions();
<?php elseif ($_REQUEST['address'] && $_REQUEST['token']): ?>
      core.runMod('login', false, function(){
        login.loadLogin();
        login.loadResetPwd('<?php echo $_REQUEST['app'] . "', '" . $_REQUEST['address'] . "', '" . $_REQUEST['token'];?>');
      });
<?php else : ?>
      core.runMod('login', false, function(){
        login.autologin(<?php echo $_REQUEST['app'] ? "'" . $_REQUEST['app'] . "'" : ''; ?>);
        login.loadLogin('<?php echo $_REQUEST['app']; ?>');
      });
<?php endif; ?>
    });
<?php if(utils::is_online()) : ?>
		var _gaq = _gaq || [];_gaq.push(['_setAccount', 'UA-10461068-18']);_gaq.push(['_trackPageview']);(function() { var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);})();
<?php endif;?>  
  </script>
</head>

<body<?php echo !utils::canUser('enter') ? ' class="login"' : '' ?>></body>
	
</html>

<?php
}
catch (myException $e)
{
	$e->log();
	
	if ($_GET['debug'])
	{
		$text = $e->getMessage() . '<br />' . $e->getCode() . '<br />in file:' . $e->getFile() . '<br />in line: ' . $e->getLine() . '<br />' . $e->getTrace();
		
		echo $e->__toString(), 'error', true;
	}
	else
	{
		echo "<h2>Errore.</h2><p>Dettaglio: " . $e->getMessage() . '</p>';
		var_dump($e);
	}  
}
catch (Exception $e)
{
	error_log($e->__toString(), 3, ERROR_LOG);
}


ob_end_flush(); 
?>