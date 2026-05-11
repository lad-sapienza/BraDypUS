<?php
/**
 * @copyright 2007-2022 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since Aug 10, 2012
 *
 * @deprecated The file-on-disk export workflow is replaced in v5 by
 *             record_ctrl::exportRecords(), which streams the file directly
 *             to the browser without writing to disk.
 *             This class is kept for v4 template compatibility only.
 */

use DB\Export\Export;

/** @deprecated See class-level docblock. */
class myExport_ctrl extends Controller
{
    /**
     * Returns HTML with folder content.
     *
     * @deprecated The export folder is no longer used in v5.
     *             Files are streamed directly from record_ctrl::exportRecords().
     */
    public static function getContent()
    {
        $content = \utils::dirContent(PROJ_DIR . 'export/');

        if (is_array($content)) {
            $html = '<table class="table table-striped table-bordered">';
            foreach ($content as $file) {
                $html .= '<tr>'
                    . '<td>' . $file . '</td>'
                    . '<td>' . round(filesize(PROJ_DIR . 'export/' . $file) / 1024 / 1024, 3) . ' MB</td>'
                    . '<td><button class="download btn btn-primary" data-file="' . PROJ_DIR . 'export/' . $file . '"><i class="fa fa-download"></i> ' . \tr::get('download') . '</button> '
                    . (\utils::canUser('edit') ? '<button type="button" class="erase btn btn-danger" data-file="' . $file . '"><i class="fa fa-trash"></i> ' . \tr::get('erase') . '</button>' : '') . '</td>'
                    . '</tr>';
            }
            $html .= '</table>';

            echo $html;
        }
    }

    /**
     * Exports data and saves the file to the project export/ folder.
     *
     * @deprecated Use record_ctrl::exportRecords() instead, which streams
     *             the file directly to the browser without disk I/O.
     */
    public function doExport()
    {
        $tb          = $this->get['tb'];
        $format      = $this->get['format'];
        $obj_encoded = $this->get['obj_encoded'];

        try {
            list($where, $values) = \SQL\SafeQuery::decode($obj_encoded);

            $where = $where ?: '1=1';

            $file = PROJ_DIR . 'export/' . $tb . '.' . date('U');

            $exp = new Export($this->db, $tb, $where, $values);

            if ($exp->saveToFile($format, $file)) {
                $this->response('export_success', 'success');
            } else {
                $this->response('export_error', 'error');
            }
            return;
        } catch (\Throwable $e) {
            $this->log->error($e);
            $this->response('export_error', 'error');
        }
    }

    /**
     * Erases an exported file.
     *
     * @deprecated The export folder is no longer used in v5.
     */
    public function erase()
    {
        $file = $this->get['file'];
        try {
            $a = @unlink(PROJ_DIR . 'export/' . $file);

            if (!$a) {
                throw new \Exception(\tr::get('error_erasing_file', [$file]));
            }
            $this->response('success_erasing_file', 'success', [$file]);
        } catch (\Exception $e) {
            $this->response($e->getMessage(), 'error');
        }
    }
}
