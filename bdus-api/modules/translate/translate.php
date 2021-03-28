<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 12, 2012
 */

class translate_ctrl extends Controller
{
	public function showList()
	{
		$lang_files = \utils::dirContent(MAIN_DIR . 'locale/');
		$available_lang = [];

		foreach ($lang_files as $file) {
            if (strpos($file, '.json') !== false) {
                $available_lang[] = str_replace('.json', null, $file);
            }
		}
		$this->render('translate', 'showList', [
			'available_lang' => $available_lang
		]);
	}
	
	public function showForm()
	{
		$lang_to_edit = $this->get['lang'];

		$edit_lang 	= $this->getAllString($lang_to_edit);

		$this->render('translate', 'showForm', [
			'lng' => $lang_to_edit,
			'main_lng' => 'en',
			'main_lng_data' => $this->getAllString('en'),
			'edit_lang' => $edit_lang
		]);
	}
	
	public function newLang()
	{
		$lang = $this->get['lang'];
		
		if ( file_exists(MAIN_DIR . 'locale/' . $lang . '.json') ){
			$this->response('error_lang_exists', 'error', [$lang]);
			return;
		}
    
		if ($this->arrayToFile($lang, [])) {
			$this->response('ok_lang_create', 'success');
		} else {
			$this->response('error_lang_create', 'error');
		}
	}
	
	public function saveData()
	{
		$lang = $this->get['lang'];
		$data = $this->post;
		
		if($this->arrayToFile($lang, $data)) {
			$this->response('ok_language_update', 'success');
		} else {
			$this->response('error_language_update', 'error');
		}
		
	}

	private function arrayToFile(string $lang, array $data): bool
	{
		return file_put_contents(MAIN_DIR . 'locale/' . $lang . '.json', json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
	}

	private function getAllString(string $lang): array
	{
		if (!file_exists(MAIN_DIR . 'locale/' . $lang . '.json')){
			throw new Exception("Language file {$lang}.json not found");
		}

		$arr = json_decode(
			file_get_contents(MAIN_DIR . 'locale/' . $lang . '.json'),
			true
		);
		if (!is_array($arr)){
			throw new Exception("Syntax error in anguage file {$lang}.json");
		}
		return $arr;
	}
		
}