<?php
/**
 * @copyright 2008-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since 4.0.0
 */
declare(strict_types = 1);

namespace Bdus;

use JShrink\Minifier;
use Monolog\Logger;

class CompressAssets
{
    public static $js_compress_libs = [
        'php2js.js',
        'jquery.keyboard.js',
        'utils.js',
        'jquery.fineuploader-3.4.0.js',
        'core.js',
        'api.js',
        'layout.js',
        'formControls.js',
        'enhanceForm.js',
        'jquery.checklabel.js',
        'jquery.printElement.js',
        'jquery.jqplot.js',
        'jqplot.barRenderer.min.js',
        'jqplot.categoryAxisRenderer.min.js',
        'jqplot.pointLabels.js',
        'export-jqplot-to-png.js',
        'jquery.insertAtCaret.js',
        'hashActions.js',
    ];

    public static function All(Logger $log = null)
    {
        return  self::moduleJS($log) 
                && 
                self::Css($log)
                && 
                self::Js($log, self::$js_compress_libs);
    }

    public static function moduleJS(Logger $log = null): bool
    {
        $error = false;

        $basedir = "modules/";

        $modules = array_diff( scandir($basedir), ['.', '..'] );

        foreach ($modules as $mod) {
            try {
                $js_file = $basedir . $mod . '/' . $mod. '.js';
                $compress_file = $basedir . $mod . '/' . $mod . '.min.js';

                if (is_dir($basedir . $mod) && file_exists($js_file) ) {
                    
                    $str = "/*\n * Copyright BraDypUS \n */";
                    $str .= Minifier::minify(file_get_contents($js_file));

                    if (!self::write_if_different( $compress_file, $str, $log) ){
                        $error = true;
                    }
                }
            } catch (\Throwable $e) {
                if ($log){ $log->error($e); }
                $error = true;
            }
        }
        
        return $error ? false : true;
    }
    
    public static function Css (Logger $log = null) : bool
	{
        $main_less = './css-less/main.less';
        $min_file = './css/bdus.min.css';

		if ( !file_exists($main_less)) {
            if ($log){
                $log->error("File `$main_less` not found. Nothing to compress");
            }
            return false;
        }
        $min_css= "/*\n * BraDypUS css minified archive includes different sources and licenses" .
                "\n * For details on external libraries (copyrights and licenses) please consult the Credits information" .
                "\n*/\n";

        try {
            $parser = new \Less_Parser([
                'compress' => true
            ]);
            $parser->parseFile( $main_less );
            $min_css .= $parser->getCss();

            return self::write_if_different( $min_file, $min_css, $log);

        } catch (\Throwable $e) {
            if ($log) { $log->error($e); }
        }
        return false;
    }

    public static function Js( Logger $log = null ): bool
	{
        $minified_file = './js/bdus.min.js';

        $str = [];
        $str_to_write[] = "/*\n * BraDypUS javascripts minified archive includes different sources and licenses";
        $str_to_write[] =  "\n * For details on external libraries (copyrights and licenses) please consult the Credits information";
        $str_to_write[] =  "\n */";

        foreach (self::$js_compress_libs as $file) {

            $file = ltrim($file);

            if ( file_exists( './js-sources/' . $file ) ) {
                $str_to_write[] = "\n/* $file */\n";
                if ( strpos($file, '.min') !== false ){
                    $str_to_write[] = file_get_contents ( './js-sources/' . $file );
                } else {
                    $str_to_write[] = Minifier::minify( file_get_contents ( './js-sources/' . $file ) );
                }
            } else {
                if ($log) { $log->warning("JS file `$file` not found"); }
            }
        }
        $mini_js_text = implode("\n", $str_to_write);

        return self::write_if_different( $minified_file, $mini_js_text, $log);
    }
    
    private static function write_if_different(string $dest_file, string $text, Logger $log): bool
    {
        if ( file_exists($dest_file) && hash_file('sha256', $dest_file) !== hash('sha256', $text ) ) {
            $deleted = @unlink($dest_file);
            if (!$deleted){
                if ($log ) { $log->warning("Cannot delete `$dest_file` file"); }
                return false;
            }
        }
        if (file_exists($dest_file)) {
            return true;
        }
        return file_put_contents($dest_file, $text) === false ? false : true;
    }
}