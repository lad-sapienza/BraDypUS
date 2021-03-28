<?php
/**
 * @copyright 2007-2021 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 * @since 4.0.0
 */


class new_app_ctrl extends Controller
{
    public function new_app_form()
    {
        $AvailableEngines = new \DB\Engines\AvailableEngines();
        if ( file_exists('./UNSAFE_permit_app_creation') || !\utils::dirContent("./projects") ){
            $this->render('new_app', 'new_app_form', [
                "db_engines" => $AvailableEngines->getList()
            ]);
        } else {
            echo tr::get('not_allowed_app_create');
        }
    }


    public function add_app()
    {
        $name           = $this->post['name'];
        $definition     = $this->post['definition'];
        $your_email     = $this->post['your_email'];
        $your_password  = $this->post['your_password'];
        $db_engine      = $this->post['db_engine'];
        $db_host        = $this->post['db_host'];
        $db_port        = $this->post['db_port'];
        $db_name        = $this->post['db_name'];
        $db_username    = $this->post['db_username'];
        $db_password    = $this->post['db_password'];

        try {
            
            $createApp = new \DB\System\CreateApp(
                $name, 
                $definition, 
                $your_email, 
                $your_password, 
                $db_engine,
                $db_host,
                $db_port,
                $db_name,
                $db_username,
                $db_password
            );
            $createApp->createAll();
            $log = $createApp->getLog();

            $this->response( 'ok_app_created', 'success', null, ["log" => $log] );


        } catch (\Throwable $e) {
            $this->response('error_app_not_created', 'error', [ $e->getMessage() ]);
            $this->log->error($e);
        }
    }


    
}