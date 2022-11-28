<?php
/**
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Aug 11, 2012
 */

class frontpage_editor_ctrl extends Controller
{
	/**
	 * returns filepath
	 */
	private function getFile(){
		return PROJ_DIR . 'welcome.html';
	}
	
	/**
	 * Reads content from files and echoes it
	 */
	public function get_content()
	{
		$file = $this->getFile();
		echo file_get_contents($file);
		
	}
	
	/**
	 * Saves content to file, stripslashed and with no php tags
	 * @param array $content array(text=>content)
	 */
	public function save_content()
	{
		$text = $this->post['text'];
		$file = $this->getFile();

		$text = stripslashes($text);
		$text = str_replace(['<?php', '<?', '?>'], '', $text);
		file_put_contents($file, $text);

	}
}