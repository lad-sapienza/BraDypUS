/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 */

 var templates = {

  init: () => {
    core.open({
      title: core.tr('tmpl_mng'),
      obj: 'templates_ctrl',
      method: 'ui'
    });
	},

  openEditForm: (tmpl, dest)=> {
    core.getHTML( 'templates_ctrl',  'openEditForm',  { "tmpl": tmpl },  false, 
      html => {
        dest.html(html)
      })
  },

  saveTmpl: ( tmpl, content ) => {
    core.getJSON('templates_ctrl', 'saveContent', {
      "tmpl": tmpl
    }, {
      "content": content
    }, resp => {
      core.message(resp.text, resp.status);
    });
  },
  
  createTmpl: () => {
    const filename = prompt(core.tr("enter_tmpl_name"));
    if (!filename){
      return false;
    }

    core.getJSON(
      'templates_ctrl', 
      'saveContent', {
        "tmpl": filename,
        "is_new": "1"
      },
      {
        "content": "{# Edit me. I am a Twig template #}"
      },
      resp => {
        core.message(resp.text, resp.status);
        if (resp.status === 'success'){
          layout.tabs.reloadActive();
        }
    });
  },

  deleteTmpl: tmpl => {
    const confirmation = confirm(core.tr('confirm_delete_tmpl', [ tmpl ]));
    if (!confirmation){
      return false;
    }
    core.getJSON('templates_ctrl', 'deleteTmpl', {
      "tmpl": tmpl
    }, false, resp => {
      core.message(resp.text, resp.status);
      if(resp.status === 'success'){
        layout.tabs.reloadActive();
      }
    });
  },

  renameTmpl: oldname => {
    const newname = prompt(core.tr("enter_tmpl_name"), oldname);
    if (!newname){
      return false;
    }

    core.getJSON('templates_ctrl', 'renameTmpl', {
      "old": oldname,
      "new": newname
    }, false, resp => {
      core.message(resp.text, resp.status);
      if(resp.status === 'success'){
        layout.tabs.reloadActive();
      }
    });
  }

 }