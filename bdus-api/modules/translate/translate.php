<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Aug 12, 2012
 */

class translate_ctrl extends Controller
{
	public function showList()
	{
		$lang_files = utils::dirContent(LOCALE_DIR);
		$available_lang = [];

		foreach ($lang_files as $file) {
            if (strpos($file, '.json') !== false) {
                $available_lang[] = str_replace('.json', null, $file);
            }
		}
		echo $this->render('translate', 'showList', [
			'available_lang' => $available_lang
		]);
	}
	
	public function showForm()
	{
		$lang_to_edit = $this->get['lang'];

		$edit_lang 	= tr::getAll($lang_to_edit);

		echo $this->render('translate', 'showForm', [
			'lng' => $lang_to_edit,
			'main_lng' => 'en',
			'main_lng_data' => tr::getAll('en'),
			'edit_lang' => $edit_lang
		]);
	}
	
	public function newLang()
	{
		$lang = $this->get['lang'];
		
		if (tr::langExists($lang)){
			utils::response(
				tr::get('error_lang_exists', [$lang]),
				'error',
				true
			);
			return;
		}
    
		if (tr::arrayToFile($lang, [])) {
			echo utils::response('ok_lang_create');
		} else {
			echo utils::response('error_lang_create', 'error');
		}
	}
	
	public function saveData()
	{
		$lang = $this->get['lang'];
		$data = $this->post;
		
		if(tr::arrayToFile($lang, $data)) {
			utils::response('ok_language_update');
		} else {
			utils::response('error_language_update', 'error');
		}
		
	}
		
}