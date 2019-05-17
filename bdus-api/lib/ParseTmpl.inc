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

		$settings = unserialize(CACHE);
		$settings['autoescape'] = false;

		$twig = new Twig_Environment(new Twig_Loader_Filesystem(PROJ_TMPL_DIR), $settings);

		return $twig->render(str_replace(PROJ_TMPL_DIR, null, $template_file), array(
      'uid' => uniqid(),
      'print' => $this
		));
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

		// given template
		if ($template AND file_exists(PROJ_TMPL_DIR . $template . '.twig'))
		{
			return PROJ_TMPL_DIR . $template . '.twig';
		}
		// preference saved template
		else if (pref::getTmpl($this->tb, $context) && file_exists(PROJ_TMPL_DIR . pref::getTmpl($this->tb, $context)))
		{
			return PROJ_TMPL_DIR . pref::getTmpl($this->tb, $context);
		}
		// config, context bound, template
		else if (cfg::tbEl($this->tb, 'tmpl_' . $context) && file_exists(PROJ_TMPL_DIR . cfg::tbEl($this->tb, 'tmpl_' . $context)))
		{
			return PROJ_TMPL_DIR . cfg::tbEl($this->tb, 'tmpl_' . $context);
		}
		// default, context bound template: {tb_name}_{context}.twig eg. sitarc__siti_edit.twig
		else if (file_exists(PROJ_TMPL_DIR . $tb . '_' . $context . '.twig'))
		{
			return PROJ_TMPL_DIR . $tb . '_' . $context . '.twig';
		}
		// default, context indipendent template
		else if (file_exists(PROJ_TMPL_DIR . $tb . '.twig'))
		{
			return PROJ_TMPL_DIR . $tb . '.twig';
		}
		// default, no template (field::showall will be used!)
		else
		{
			return 'default';
		}
	}

}

?>
