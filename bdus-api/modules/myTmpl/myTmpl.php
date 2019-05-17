<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 11, 2012
 */

class myTmpl_ctrl extends Controller
{
	public function show()
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
				if (preg_match('/' . str_replace(PREFIX, null, $tb) . '/', $tmpl))
				{
					$data[$tb]['list'][] = $tmpl;
				}
			}
			$data[$tb]['default_read'] = cfg::tbEl($tb, 'tmpl_read');
			$data[$tb]['default_edit'] = cfg::tbEl($tb, 'tmpl_edit');
			$data[$tb]['user_read'] = pref::getTmpl($tb, 'read');
			$data[$tb]['user_edit'] = pref::getTmpl($tb, 'edit');
		}

    $this->render('myTmpl', 'user_tmpl', array(
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
	public function change()
	{
    pref::setTmpl($this->get['param'][0], $this->get['param'][1], $this->get['param'][2]);
	}
}
