<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			All rights reserved
 * @since			Aug 11, 2012
 */

class myTmpl_ctrl
{
	public static function show()
	{
		$tbs = cfg::getNonPlg();
		
		$tmpls = utils::dirContent(PROJ_TMPL_DIR);
		
		if(!is_array($tmpls))
		{
			echo json_encode(array('status'=>'error', 'text'=>tr::get('no_tmpl_available')));
			return;
		}
		
		$data = array();
		
		foreach ($tbs as $tb=>$label)
		{
			foreach ($tmpls as $tmpl)
			{
				if (preg_match('/' . str_replace(PREFIX . '__', null, $tb) . '/', $tmpl))
				{
					$data[$tb]['list'][] = $tmpl;
				}
			}
			$data[$tb]['default_read'] = cfg::tbEl($tb, 'tmpl_read');
			$data[$tb]['default_edit'] = cfg::tbEl($tb, 'tmpl_edit');
			$data[$tb]['user_read'] = pref::getTmpl($tb, 'read');
			$data[$tb]['user_edit'] = pref::getTmpl($tb, 'edit');
		}
		
		$twig = new Twig_Environment(new Twig_Loader_Filesystem(MOD_DIR . 'myTmpl/tmpl'), unserialize(CACHE));
		
		echo $twig->render('user_tmpl.html', array(
				'tr' => new tr(),
				'tabs' => $tbs,
				'data' => $data,
				'uid' => uniqid('tmpl')
		));
		
	}
	
	/**
	 * Saves user preferences to session (pref)
	 * @param string $tb
	 * @param string $context
	 * @param string $tmpl template name with extension
	 */
	public static function change($tb, $context, $tmpl = false)
	{
		pref::setTmpl($tb, $context, $tmpl);
	}
}