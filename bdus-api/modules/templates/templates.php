<?php

class templates_ctrl extends Controller
{

  public function ui(): void
  {
    $available_tmpls = \utils::dirContent(PROJ_DIR . 'templates');
    $this->render('templates', 'ui', [
      'available_tmpls' => $available_tmpls
    ]);
  }


  public function openEditForm()
  {
    $tmpl = $this->get['tmpl'];
    
    try {
      $file = PROJ_DIR . 'templates/' . $tmpl;

      if (!file_exists($file)) {
        throw new \Exception(tr::get("file_not_found", [$file]));
      }

      $content = file_get_contents($file);

      if (!$content) {
        throw new \Exception(tr::get('error_getting_content_of_file', [$file]));
      }

      $content = htmlentities($content);

    } catch (\Throwable $th) {
      $error = $th->getMessage();
    }

    echo $this->render('templates', 'editForm', [
      "tmpl" => $tmpl,
      "content" => $content,
      "error" => $error
    ]);
  }

  public function saveContent()
  {
    $tmpl = $this->get['tmpl'];
    $is_new = $this->get['is_new'];
    $content = $this->post['content'];

    $file = PROJ_DIR . 'templates/' . $tmpl;

    if ($is_new && file_exists($file)){
      $this->returnJson([
        "status" => "error",
        "text" => tr::get('tmpl_file_exists', [$file])
      ]);
      return;
    }

    $res = file_put_contents($file, $content);

    $response = $res ? [
      "status" => "success",
      "text" => tr::get("tmpl_file_updated", [$file])
    ] : [
      "status" => "error",
      "text" => tr::get('tmpl_file_not_updated', [$file])
    ];

    $this->returnJson($response);

  }

  public function deleteTmpl()
  {
    $tmpl = $this->get['tmpl'];
    try {
      $file = PROJ_DIR . 'templates/' . $tmpl;

      if (!file_exists($file)) {
        throw new \Exception(tr::get('file_not_found', [$file]));
      }

      unlink($file);

      if (file_exists($file)) {
        throw new \Exception(tr::get('tmpl_file_not_deleted', [$file]));
      }

      $this->returnJson([
        "status" => "success",
        "text" => tr::get('tmpl_file_deleted', [$file])
      ]);

    } catch (\Throwable $th) {
      $this->returnJson([
        "status" => "error",
        "text" => $th->getMessage()
      ]);
    }
  }

  public function renameTmpl()
  {
    $old = PROJ_DIR . 'templates/' . $this->get['old'];
    $new = PROJ_DIR . 'templates/' . $this->get['new'];

    try {
        if (!file_exists($old)) {
          throw new \Exception(tr::get('file_not_found', [$old]));
        }
        if (file_exists($new)) {
          throw new \Exception(tr::get('tmpl_file_exists', [$new]));
        }

        $res = rename($old, $new);
        if (!$res){
          throw new \Exception(tr::get('tmpl_file_not_renamed', [$old, $new]));
        }

        $this->returnJson([
          "status" => "success",
          "text" => tr::get('tmpl_file_renamed', [$old, $new])
        ]);
    } catch (\Throwable $th) {
      $this->returnJson([
        "status" => "error",
        "text" => $th->getMessage()
      ]);
    }
  }
}
