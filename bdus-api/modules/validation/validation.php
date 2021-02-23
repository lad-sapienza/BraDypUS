<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since			Jan 10, 2013
 */


class validation_ctrl extends Controller
{
    public function validate()
    {
        $type = $this->request['type'];
        switch ($type) {
            case 'duplicates':
                $this->check_duplicates();
                break;
            
            case 'wkt':
                $this->check_wkt();
                break;
            
            default:
            throw new \Exception('Validation type is reuired');
                break;
        }
    }

    private function check_wkt()
    {
        try {
            if (!$this->request['val']) {
                throw new \Exception('Field value is required');
            }
            $geo = \geoPHP::load($this->request['val'], 'wkt');
            if (!$geo){
                throw new \Exception("String {$this->request['val']} is not valid WKT");
            }
            
        } catch (\Exception $e) {
            echo 'error';
        }
    }

    private function check_duplicates()
    {
        try {
            if (!$this->request['fld'] || !$this->request['val']) {
                throw new \Exception('Field name and value are required');
            }
            if (preg_match('/core\[/', $this->request['fld'])) {
                $arr = explode('][', preg_replace('/core\[(.+)\]/', '$1', $this->request['fld']));

                $query = 'SELECT count(*) as tot FROM ' . $arr[0] . ' WHERE ' . $arr[1] . ' = :' . $arr[1];

                $res = $this->db->query($query, [":{$arr[1]}" => $this->request['val']], 'read');

                if ($res[0]['tot'] > 0) {
                    echo 'error';
                }
            }
        } catch (\DB\DBException $e) {
            echo 'error';
        } catch (\Exception $e) {
            echo 'error';
        }
    }
}
