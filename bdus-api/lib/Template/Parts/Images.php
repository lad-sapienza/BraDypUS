<?php
/**
* @copyright 2007-2022 Julian Bogdani
* @license AGPL-3.0; see LICENSE
*/

namespace Template\Parts;

use \Intervention\Image\ImageManager;


class Images
{
    /**
    * Returns HTML code for showing galley of files/images
    *
    * @param array $data_arr
    * @param integer $max
    * @param string $tb
    * @param integer $id
    * @param string $context
    * @param boolean $canUserEdit
    * @return string
    */
    public static function showAll( array $data_arr = [], int $max = 2, string $tb, int $id = null, string $context, bool $canUserEdit, string $app) : string

    {
        // max can not be bigger than the no of images found in the DB
        if ($max === 0 || $max > count($data_arr)) {
            $max = count($data_arr);
        }
        
        $filtered = array_slice($data_arr, 0, $max);
        // start writing main container. Styles are handled via CSS
        $html = '<div class="file_thumbnails">';
        
        // 1. Show up to $max images
        foreach ($filtered as $img) {
            if (!empty($img)) {
                $html .= self::getThumbHtml($img, "projects/{$app}/files/");
            }
        }
        
        
        // write anyway service div
        $html .= '<div class="preview-item">';
        
        
        // 2. Show view all images button
        if (!empty($data_arr[0]) && $tb !== PREFIX . 'files') {
            $html .= '<div class="gal_button">' .
            '<button type="button" ' .
            'class="btn btn-info btn-block" ' .
            'onclick="api.file.show_gallery(\'' . $tb . '\', \'' . $id . '\');">' .
            '<i class="fa fa-file"></i> ' .
            \tr::get('x_files_linked', [count($data_arr)]) .
            '</button>' .
            '</div>';
        }
        
        // 3. Show image replace button if context is EDIT and current table is FILES (no need to check if there are any files found in DB. One file is allways present)
        if ($tb === PREFIX . 'files' && $context === 'edit') {
            $uid = uniqid('newfile');
            
            $html .= '<div id="' . $uid . '" class="load_img_placeholder">sostituisci l\'immagine</div>' .
            '<script>' .
            "api.fileUpload($('#" . $uid . "'), './?obj=file_ctrl&method=upload', {" .
                "'button_text': '" . str_replace("'", "\'", \tr::get('replace_file') ). "'," .
                "'complete': function(id, fileName, resp){" .
                    "$('#{$uid}').parents('form').find('input[name*=filename]').val(resp.filename).attr('changed', 'auto');" .
                    "$('#{$uid}').parents('form').find('input[name*=ext]').val(resp.ext).attr('changed', 'auto');" .
                    "$('#{$uid}').parents('form').find('div.file_thumbnails').html(resp.thumbnail);" .
                    "}" .
                    "});" .
                    '</script>';
        }
                
        // 4. Show "upload new file" button if context is ADD_NEW and current table is FILES
        if ($tb === PREFIX . 'files' && $context === 'add_new') {
            $uid = uniqid('newfile');
            
            $html .= '<div id="' . $uid . '" class="load_img_placeholder"></div>' .
            '<script>' .
            "api.fileUpload($('#" . $uid . "'), './?obj=file_ctrl&method=upload', {" .
                "'limit2one': true," .
                "'complete': function(id, fileName, resp){" .
                    "$('#{$uid}').parents('form').find('input[name*=filename]').val(resp.filename).attr('changed', 'auto');" .
                    "$('#{$uid}').parents('form').find('input[name*=ext]').val(resp.ext).attr('changed', 'auto');" .
                    "if (resp.success){" .
                        "$('#{$uid}').html('div.file_thumbnails').html(resp.thumbnail);" .
                        "}" .
                        "}" .
                        "});" .
                        '</script>';
        }
                            
                            
        // 5. Attach file to record if current table is not FILES, context is EDIT and user can edit
        if ($tb !== PREFIX . 'files' && $context === 'edit' && $canUserEdit ) {
            $uid = uniqid('newfile');
            
            $html .= '<div id="' . $uid . '" class="load_img_placeholder"></div>' .
            '<script>' .
            "api.fileUpload($('#" . $uid . "'), './?obj=file_ctrl&method=uploadLink&dest_table=" . $tb . "&dest_id=" . $id . "', {" .
                "'button_text' : '" . str_replace("'", "\'", \tr::get('click_drag_link_file')) . "'," .
                "'limit2one': true," .
                "'complete': function(id, fileName, resp){" .
                    "if(resp.status === 'success'){" .
                        "$('#{$uid}').parents('div.file_thumbnails').prepend('<div class=\"preview-item\">' + resp.thumbnail + \"</div>\");" .
                    "}" .
                    "core.message(resp.text, resp.status);" .
                "}" .
            "});" .
            '</script>';
        }
        // end of service div
        $html .= '</div>';
        
        // end of main file container
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Returns ordered and associative array containing verbose file type and icon
     *
     * @param boolean $ext
     * @return array
     */
    private static function checkExt($ext = false): array
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

        $ret = [ 
            "Unknown filetype", 
            "unknown.png", 
            'type' => "Unknown filetype", 
            'icon' => "unknown.png"
        ];

        if ( $ext ) {
            foreach ( $availableTypes as $name => $arr_values ) {
                if ( in_array(strtolower($ext), $arr_values['ext']) ) {
                    $ret = [ 
                        $name, 
                        $arr_values['icon'], 
                        'type' => $name, 
                        'icon' => $arr_values['icon']
                    ];
                }
            }
        }
        return $ret;
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
    public static function getThumbHtml($file_array, string $path): string
    {
        $data = self::checkExt($file_array['ext']);
        
        if ($data['type'] === 'image') {
            $thumb = self::getThumb($path . $file_array['id'] . '.' . $file_array['ext']);
            
            $html = '<div class="preview-item">'
            . '<a data-fancybox href="' . $path . $file_array['id'] . '.' . $file_array['ext'] . '"> '
            . '<img class="" '
            . 'src="' . $thumb . '" '
            . ( $file_array['description'] ? ' alt="' . str_replace('"', '', $file_array['description']) . '" ' : ''  )
            . ( $file_array['description'] ? ' title="' . str_replace('"', '', $file_array['description']) . '" ' : '')
            . ' =""'
            . '/>'
            . '</a></div>';
        } else {
            $html = '<div class="preview-item">'
            . '<a href="' . $path . $file_array['id'] . '.' . $file_array['ext'] . '" target="_blank">'
            . '<img src="assets/bdus/img/mime_icons/' . $data['icon'] . '">'
            . '</a>'
            .'</div>';
        }
        return $html;
    }
    
    private static function getThumb( string $file ): string
    {
        if (!\is_dir("cache/img")){
            \mkdir("cache/img");
        }
        $hash = md5_file($file);
        $thumb = "cache/img/{$hash}";
        if (!file_exists($thumb)){
            $im = new ImageManager();
            $img = $im->make($file)->resize(200, 200, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })->save($thumb)->destroy();
        }
        if (!file_exists($thumb)){
            return $file . '?' . $hash;
        }
        return $thumb;
    }
}