<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 26, 2012
 */

class tbs_editor_ctrl extends Controller
{
	public function save()
	{
		$post = $this->post;

		try {

			$post = utils::recursiveFilter($post);

			// make indexed array for links and geoface
			if($post['link']) {

				$post['link'] = array_values($post['link']);

				$tmp = array_values($post['link']);

				foreach ($tmp as &$link) {
					$link['fld'] = array_values($link['fld']);
				}

				$post['link'] = false;
				$post['link'] = $tmp;
			}

			if($post['geoface']['layers']['local']) {

				$tmp = array_values($post['geoface']['layers']['local']);

				$post['geoface']['layers']['local'] = false;
				$post['geoface']['layers']['local'] = $tmp;
			}


			if ($post['is_plugin'] == 1 && (!$post['name'] || !$post['label'] )) {

				throw new myException('1. Required fields are missing');

			} else if ( (!$post['is_plugin'] || $post['is_plugin'] == 0) && ( !$post['name'] || !$post['label'] || !$post['order'] && !$post['id_field'] || !$post['preview'] ) ) {

				throw new myException('2. Required fields are missing');
			}

			cfg::setTb($post);

			utils::response('ok_save_tb_data');

		} catch(myException $e) {
			
			$e->log();
			utils::response('error_save_tb_data', 'error');
		}
	}

	public function tb_list()
	{
		echo $this->render('tbs_editor', 'main', array(
				'tbs_data' => cfg::tbEl('all', 'label'),
				'uid' => uniqid('ed')
				));
	}



	public function show_form()
	{
		$data = cfg::tbEl($this->request['tb'], 'all');

		// requested values
		if (!$data['preview']) $data['preview'] = array(0=>'');
		if (!$data['plugin']) $data['plugin'] = array(0=>'');
		if (!$data['link']) $data['link'] = array(0=>array('fld'=>array(0=>'')));

		if (!$data['geoface']) $data['geoface'] = array(0 => '');
		if (!$data['geoface']['layers']['local']) $data['geoface']['layers']['local'] = array(0=>'');
		if (!$data['geoface']['layers']['excludeWeb']) $data['geoface']['layers']['excludeWeb'] = array(0=>'');

		echo $this->render('tbs_editor', 'form', array(
				'data' => $data,
				'tb' => $this->get['tb'],
				'fields' => cfg::fldEl($this->request['tb'], 'all', 'label'),
				'templates' => utils::dirContent(PROJ_TMPL_DIR),
				'available_plugins' => is_array(cfg::getPlg()) ? cfg::getPlg() : array(),
				'available_tables' => cfg::tbEl('all', 'label'),
				'available_epsgs' => array('4326'=>'WGS84 (EPSG:4326)', '900913' => 'Google Mercator (EPSG:900913)'),
				'available_geoface_layers' => is_array(utils::dirContent(PROJ_GEO_DIR)) ? utils::dirContent(PROJ_GEO_DIR) : array()
				)
				);
	}


}
