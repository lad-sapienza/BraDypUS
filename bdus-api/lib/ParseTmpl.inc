<?php
/**
 *
 * Parses a template (XML) file and shows read and/or edit content
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since 2011-08-11
 */

class ParseTmpl extends Field
{

	/**
	 *
	 * Parses a template file and returns $html
	 */
	function parseAll ( $template = false )
	{
		$template_file = $this->setTemplate($template);

		if ($template_file == 'default')
		{
			return $this->showall();
		}

		$twig = new \Twig\Environment( new \Twig\Loader\FilesystemLoader(PROJ_DIR . 'templates/'), unserialize(CACHE) );

		return $twig->render(str_replace(PROJ_DIR . 'templates/', null, $template_file), [
			'uid' => uniqid(),
			'print' => $this
		]);
	}


	/**
	 *
	 * Gets table name and context (template type) and returns a valid template path
	 * If no file exists at given path an exeption will be thrown.
	 * @param string $template template file name, default is false
	 */
	private function setTemplate ($template = false)
	{
		$tb = str_replace(PREFIX, null, $this->tb);

		$context = $this->context == 'add_new' ? 'edit' : $this->context;

		if ($template AND file_exists(PROJ_DIR . 'templates/' . $template . '.twig')) {
		
			// given template
			return PROJ_DIR . 'templates/' . $template . '.twig';

		} else if (pref::getTmpl($this->tb, $context) && file_exists(PROJ_DIR . 'templates/' . pref::getTmpl($this->tb, $context))) {

			// preference saved template
			return PROJ_DIR . 'templates/' . pref::getTmpl($this->tb, $context);

		} else if (cfg::tbEl($this->tb, 'tmpl_' . $context) && file_exists(PROJ_DIR . 'templates/' . cfg::tbEl($this->tb, 'tmpl_' . $context))) {

			// config, context bound, template
			return PROJ_DIR . 'templates/' . cfg::tbEl($this->tb, 'tmpl_' . $context);

		} else if (file_exists(PROJ_DIR . 'templates/' . $tb . '_' . $context . '.twig')) {

			// default, context bound template: {tb_name}_{context}.twig eg. sitarc__siti_edit.twig
			return PROJ_DIR . 'templates/' . $tb . '_' . $context . '.twig';

		} else if (file_exists(PROJ_DIR . 'templates/' . $tb . '.twig')) {

			// default, context indipendent template
			return PROJ_DIR . 'templates/' . $tb . '.twig';

		} else {

			// default, no template (field::showall will be used!)
			return 'default';
		}
	}

}

?>
