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
	public static function availableTypes()
	{
		$availableTypes = array(
			"Simple text" => array(
					"ext" => array("txt"),
					"icon" => "text-plain.png"),
			"HTML" => array(
					"ext" => array("html", "xhtml"),
					"icon" => "text-html.png"),
			"CSS" => array(
					"ext" => array("css"),
					"icon" => "text-css.png"),
			"JavaScript" => array(
					"ext" => array("js", "json"),
					"icon" => "application-javascript.png"),
			"XML" => array(
					"ext" => array("xml"),
					"icon" => "application-xml.png"),
			"Video" => array(
					"ext" => array("swf", "flv", "qt", "mov"),
					"icon" => "video.png"),
			"Vector" => array(
					"ext" => array("svg", "ai", "eps", "ps"),
					"icon" => "vector.png"),
			"Archive" => array(
					"ext" => array("zip", "rar", "cab"),
					"icon" => "archive.png"),
			"EXE" => array(
					"ext" => array("exe", "msi"),
					"icon" => "executable.png"),
			"Audio" => array(
					"ext" => array("mp3", "mp4", "wma", "wav", "ogg"),
					"icon" => "audio.png"),
			"PDF" => array(
					"ext" => array("pdf"),
					"icon" => "application-pdf.png"),
			"Image Manipulation" => array(
					"ext" => array("psd", "xcf"),
					"icon" => "image-x-generic.png"),
			"Document" => array(
					"ext" => array("doc", "docx", "rtf", "odt"),
					"icon" => "application-msword.png"),
			"Spreadsheet" => array(
					"ext" => array("xls", "xlsx", "ods"),
					"icon" => "application-vnd.ms-excel.png"),
			"Presentation" => array(
					"ext" => array("ppt", "pptx", "odp"),
					"icon" => "application-vnd.ms-powerpoint.png"),
			"image" => array(
					"ext" => array("png", "jpeg", "jpg", "bmp", "ico", "tif", "tiff"),
					"icon" => "image-x-generic.png"),
		);
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
			})->save();
		return true;
	}

	/**
	 *
	 * Returns ordered and associative array containing verbose file type and icon
	 * @param string $ext	file extension
	 */
	public static function checkExt($ext = false)
	{

		// cycle in values
		foreach ( self::availableTypes() as $name=>$arr_values )
		{
			if ( $ext )
			{
				if ( in_array(strtolower($ext), $arr_values['ext']) )
				{
					return array($name, $arr_values['icon'], 'type'=>$name, 'icon'=>$arr_values['icon']);
				}
			}
		}
		// if the cycle was not interrupted, no ext was found: return unknown file:
		return (array("Unknown filetype", "unknown.png"));
	}

	/**
	 *
	 * Returns html string for image preview
	 * @param array $file_array array with file data (file table row)
	 * 			id: required
	 * 			ext: required
	 * 			description: optional
	 * @param string $path, if null defult file path (PROJ_FILES_DIR) will be used
	 */
	public static function getThumbHtml($file_array, $path = false)
	{
		$path = $path ? $path : PROJ_FILES_DIR;

		$data = self::checkExt($file_array['ext']);

		if ($data['type'] == 'image')
		{
			$html = '<div style="padding: 5px;">'
				. '<img class="thumbnail" '
					. 'src="' . $path . $file_array['id'] . '.' . $file_array['ext'] . '?' . uniqid('file') . '" '
					. 'style="max-width:200px; max-height:200px;" '
					. ( $file_array['description'] ? ' alt="' . str_replace('"', null, $file_array['description']) . '" ' : ''  )
					. ( $file_array['description'] ? ' title="' . str_replace('"', null, $file_array['description']) . '" ' : '')
					. ' onclick="api.preview(this)"'
					. '/>'
			. '</div>';
		}
		else
		{
			$html = '<div style="cursor:pointer; width:200px; height:200px; background:url(' . MAIN_DIR . 'img/mime_icons/' . $data['icon'] . ') no-repeat center center;"'
			. ' onclick="window.open(\'' . $path . $file_array['id'] . '.' . $file_array['ext'] . '\')"></div>';
		}
		return $html;
	}
}
