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
    $opened_lang = $this->request['param'][0];
    
		$langs = utils::dirContent(LOCALE_DIR);
		
		$uid = uniqid('transl');
		
		$html = '<h2>' . tr::get('select_lang_to_edit') . '</h2>';
		
		$html .= '<div id="but_' . $uid . '">';
		
		foreach($langs as $l)
		{
			if ($l != 'it.inc')
			{
				$ll = str_replace('.inc', null, $l);
				$html .= '<button type="button" data-lang="' . $ll . '" class="lang btn btn-info"><i class="glyphicon glyphicon-white glyphicon-globe"></i> ' . strtoupper($ll) . '</button> ';
			}
		}
		$html .= ' <button type="button" class="add btn btn-warning">' . tr::get('add') . '</button>';
		
				
		$html .= '</div>'
				. '<hr />'
				. '<div id="cont_' . $uid . '" class="transl-content"></div>'
				. '<script>'
						. "$('#but_{$uid} button.lang').on('click', function(){"
								. "$('#cont_{$uid}').html(core.loading).load('controller.php?obj=translate_ctrl&method=showForm&param[]=' + $(this).data('lang'));"
						."});"
						. "$('#but_{$uid} button.add').on('click', function(){"
								. "translate.addLang('{$uid}'); "
						."});"
						. ($opened_lang ? "$('#cont_{$uid}').html(core.loading).load('controller.php?obj=translate_ctrl&method=showForm&param[]={$opened_lang}')" : '')
				. '</script>';
		echo $html;
		
	}
	
	public function showForm()
	{
    
    $lng = $this->request['param'][0];

		require LOCALE_DIR . 'it.inc';
		$it = $lang;
		unset($lang);
		
		require LOCALE_DIR . $lng . '.inc';
		$edit_lang = $lang;
		unset($lang);
		
    
    $this->render('translate', 'form', array(
      'lng' => $lng,
      'it' => $it,
      'edit_lang' => $edit_lang
		));
	}
	
	public function newLang()
	{
    
    $lang = $this->request['param'][0];
    
		if (!file_exists(LOCALE_DIR . $lang . '.inc') && utils::write_in_file(LOCALE_DIR . $lang . '.inc', ''))
		{
			echo utils::response('ok_lang_create');
		}
		else
		{
			echo utils::response('error_lang_create', 'error');
		}
	}
	
	public function save()
	{
    $post = $this->post;
    
		$lang = $post['edit_lang'];
    
		unset($post['edit_lang']);

		foreach ($post as $k => $v)
		{
			$text[]='$lang[\'' . $k . '\'] = "' . str_replace(array('"', "\r\n"), array('\'', '\\n'), $v) . '";'; 
		}
		
		if(utils::write_in_file(LOCALE_DIR . $lang .'.inc', '<?php' . "\n" . implode("\n", $text)))
		{
			utils::response('ok_language_update');
		}
		else
		{
			utils::response('error_language_update', 'error');
		}
		
	}
		
}