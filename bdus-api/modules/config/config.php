<?php 

class config_ctrl extends Controller
{
    public function home()
    {
        $table_list = cfg::tbEl('all', 'label');

        $this->render('config', 'home', [
            "table_list" => $table_list
        ]);
    }

    public function app_properties()
    {
        try{
			$user = new User(new DB());
		
			$users = $user->getUser('all');
		
			foreach ($users as &$u){
				$u['verbose_privilege'] = utils::privilege($u['privilege'], 1);
			}
		} catch (myException $e){
			$users = [];
		}
		
		$this->render('config', 'app_properties', [
            'available_langs' => utils::dirContent(LOCALE_DIR),
            'info' => cfg::main(),
            'status' => [ 'on', 'frozen', 'off' ],
            'users' => $users,
            'db_engines' => ['sqlite', 'mysql', 'pgsql']
        ]);
    }


    public function fld_list()
    {
        $tb = $this->get['tb'];

		$this->render('config', 'fld_list', [
            'tb' => $tb,
            'tb_label' => cfg::tbEl($tb, 'label'),
            'all_fields' => cfg::fldEl($tb, 'all', 'all')
        ]);
    }


    public function field_properties()
    {
        $tb = $this->get['tb'];
        $fld = $this->get['fld'];

        $data = $fld ? cfg::fldEl($tb, $fld, 'all') : [];

        $voc = new Vocabulary(new DB());
		$all_voc = $voc->getAllVoc();

        $fld_structure = file_get_contents(__DIR__ . '/fld_structure.json');

        $fld_structure = str_replace(
            [
                'list-of-system-defined-vocabularie-here', 
                'list-of-available-tables-here'
            ],
            [
                implode('","', $all_voc), 
                implode('","', array_values(cfg::tbEl('all','name')))
            ],
            $fld_structure
        );
        $fld_structure = json_decode($fld_structure, TRUE);

        $this->render('config', 'field_properties', [
            'tb'    => $tb,
            'fld'   => $fld,
            'data'  => $data,
            'fld_structure' => $fld_structure 
        ]);
    }


    public function table_properties()
    {
        $tb = $this->get['tb'];

        $table_properties = cfg::tbEl($tb, 'all');
        // default values
		if (!$table_properties['preview'])  $table_properties['preview'] = array(0=>'');
		if (!$table_properties['plugin'])   $table_properties['plugin'] = array(0=>'');
		if (!$table_properties['link'])     $table_properties['link'] = array(0=>array('fld'=>array(0=>'')));

        $this->render('config', 'table_properties', [
            'data'  => $table_properties,
            'tb'    => $tb,
            'field_list' => cfg::fldEl($tb, 'all', 'label'),
            'template_list' => utils::dirContent(PROJ_DIR . 'templates/'),
            'available_plugins' => is_array(cfg::getPlg()) ? cfg::getPlg() : array(),
            'available_tables' => cfg::tbEl('all', 'label'),
        ]);

    }

    public function save_tb_data()
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

			if ($post['is_plugin'] == 1 && (!$post['name'] || !$post['label'] )) {

				throw new myException('1. Required fields are missing');

			} else if ( (!$post['is_plugin'] || $post['is_plugin'] == 0) && ( !$post['name'] || !$post['label'] || !$post['order'] && !$post['id_field'] || !$post['preview'] ) ) {

				throw new myException('2. Required fields are missing');
			}

			cfg::setTb($post);

			utils::response('ok_cfg_data_updated');

		} catch(myException $e) {
			
			$e->log();
			utils::response('error_cfg_data_updated', 'error');
		}
    }
    

    public function save_fld_properties()
	{
		$post = $this->post;
		try {
			$post = utils::recursiveFilter($post);

			$tb = $post['tb_name'];
			$fld = $post['fld_name'];
			unset($post['tb_name']);
			unset($post['fld_name']);

			if (!$post['name'] || !$post['type']){
				throw new myException('Both field name and field type are required');
			}

			cfg::setFld($tb, $fld, $post);

			utils::response('ok_cfg_data_updated');
        
        } catch(myException $e) {

			$e->log();
			utils::response('error_cfg_data_updated', 'error');
		}

    }
    
    public function save_app_properties()
    {
        $data = $this->post;
		
		try {

			cfg::setMain($data);
            utils::response('ok_cfg_data_updated');
            
		} catch (myException $e) {

			utils::response('error_cfg_data_updated', 'error');
		}
    }

    
}