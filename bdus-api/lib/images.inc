<?php
/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			Apr 29, 2012
 */

class images
{
	/**
	 *
	 * Returns array of system registered extension types and corresponding icons
	 */
	private static function availableTypes()
	{
		$availableTypes = [
			"Simple text" => [
					"ext" => [ "txt" ],
					"icon" => "text-plain.png"
				],
			"HTML" => [
					"ext" => [ "html", "xhtml" ],
					"icon" => "text-html.png"
				],
			"CSS" => [
					"ext" => [ "css" ],
					"icon" => "text-css.png"
				],
			"JavaScript" => [
					"ext" => [ "js", "json" ],
					"icon" => "application-javascript.png"
				],
			"XML" => [
					"ext" => [ "xml" ],
					"icon" => "application-xml.png"
				],
			"Video" => [
					"ext" => [ "swf", "flv", "qt", "mov" ],
					"icon" => "video.png"
				],
			"Vector" => [
					"ext" => [ "svg", "ai", "eps", "ps" ],
					"icon" => "vector.png"
				],
			"Archive" => [
					"ext" => [ "zip", "rar", "cab" ],
					"icon" => "archive.png"
				],
			"EXE" => [
					"ext" => [ "exe", "msi" ],
					"icon" => "executable.png"
				],
			"Audio" => [
					"ext" => [ "mp3", "mp4", "wma", "wav", "ogg" ],
					"icon" => "audio.png"
				],
			"PDF" => [
					"ext" => [ "pdf" ],
					"icon" => "application-pdf.png"
				],
			"Image Manipulation" => [
					"ext" => [ "psd", "xcf" ],
					"icon" => "image-x-generic.png"
				],
			"Document" => [
					"ext" => [ "doc", "docx", "rtf", "odt" ],
					"icon" => "application-msword.png"
				],
			"Spreadsheet" => [
					"ext" => [ "xls", "xlsx", "ods" ],
					"icon" => "application-vnd.ms-excel.png"
				],
			"Presentation" => [
					"ext" => [ "ppt", "pptx", "odp" ],
					"icon" => "application-vnd.ms-powerpoint.png"
				],
			"image" => [
					"ext" => [ "png", "jpeg", "jpg", "bmp", "ico", "tif", "tiff" ],
					"icon" => "image-x-generic.png"
				],
		];
		return $availableTypes;
	}

	public static function resizeIfImg($file, $maxImageSize)
	{
		$fileTypeInfo = self::checkExt(pathinfo($file, PATHINFO_EXTENSION));
		if ($fileTypeInfo['type'] !== 'image'){
			return true;
		}
		$im = new Intervention\Image\ImageManager();
		$img = $im->make($file)->resize($maxImageSize, $maxImageSize, function ($constraint) {
				$constraint->aspectRatio();
				$constraint->upsize();
			})->save()->destroy();
		return true;
	}

	/**
	 *
	 * Returns ordered and associative array containing verbose file type and icon
	 * @param string $ext	file extension
	 */
	private static function checkExt($ext = false)
	{
		if ( $ext ) {
			foreach ( self::availableTypes() as $name => $arr_values ) {
				if ( in_array(strtolower($ext), $arr_values['ext']) ) {
					return [ 
						$name, 
						$arr_values['icon'], 
						'type' => $name, 
						'icon' => $arr_values['icon']
					];
				}
			}
		}
		// if the cycle was not interrupted, no ext was found: return unknown file:
		return [ 
			"Unknown filetype", 
			"unknown.png", 
			'type' => "Unknown filetype", 
			'icon' => "unknown.png"
		];
	}

	/**
	 *
	 * Returns html string for image preview
	 * @param array $file_array array with file data (file table row)
	 * 			id: required
	 * 			ext: required
	 * 			description: optional
	 * @param string $path, if null defult file path (PROJ_DIR . 'files') will be used
	 */
	public static function getThumbHtml($file_array, $path = false)
	{
		$path = $path ? $path : 'projects/' . APP . '/files/';

		$data = self::checkExt($file_array['ext']);

		if ($data['type'] === 'image') {
			$html = '<div style="padding: 5px;">'
				. '<img class="thumbnail" '
					. 'src="' . $path . $file_array['id'] . '.' . $file_array['ext'] . '?' . uniqid('file') . '" '
					. 'style="max-width:200px; max-height:200px;" '
					. ( $file_array['description'] ? ' alt="' . str_replace('"', null, $file_array['description']) . '" ' : ''  )
					. ( $file_array['description'] ? ' title="' . str_replace('"', null, $file_array['description']) . '" ' : '')
					. ' onclick="api.preview(this)"'
					. '/>'
			. '</div>';
		} else {
			$html = '<div style="cursor:pointer; width:200px; height:200px; background:url(' . MAIN_DIR . 'img/mime_icons/' . $data['icon'] . ') no-repeat center center;"'
			. ' onclick="window.open(\'' . $path . $file_array['id'] . '.' . $file_array['ext'] . '\')"></div>';
		}
		return $html;
	}
}
