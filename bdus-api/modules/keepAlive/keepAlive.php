<?php

class keepAlive_ctrl extends Controller
{
  public function run()
  {
    session_regenerate_id();
    $this->response('Pinged', 'success');
  }
}