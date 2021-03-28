/**
* @author			Julian Bogdani <jbogdani@gmail.com>
* @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
* @license			See file LICENSE distributed with this code
*/

var user = {
  
  init: function(id, dontreload){
    if (id){
      user.showUserForm(id, dontreload);
    } else {
      user.mng();
    }
  },
  
  mng: function(){
    
    core.open({
      obj: 'user_ctrl',
      method: 'showList',
      title: core.tr('user_mng'),
      unique: true
    });
  },
  
  showUserForm: function(id, dontreload){
    core.open({
      obj:'user_ctrl',
      method:'showUserForm',
      param: { "id": id },
      buttons:[
        {
          text: core.tr('save'),
          click: function(){
            var post_data = $('#modal').find('form').serialize();
            
            if (!id){
              if(
                  !$('#modal').find('input[name="name"]').val() 
                  || !$('#modal').find('input[name="email"]').val()
                  ||!$('#modal').find('input[name="password"]').val()
                ){
                  core.message(core.tr('all_fields_needed'), 'error');
                  return;
                }
              }
              
              core.getJSON('user_ctrl', 'saveUserData', false, post_data, function(data){
                core.message(data.text, data.status);
                if (data.status == 'success'){
                  $('#modal').modal('hide');
                  if (!dontreload){
                    layout.tabs.reloadActive();
                  }
                }
              },
              'json');
            }
          },
          {
            text: core.tr('cancel'),
            action: 'close'
          }
        ]
      }, 'modal');
    },
    deleteOne: function(id){
      core.open({
        html: core.tr('confirm_erase_user'),
        buttons:[
          {
            text: core.tr('confirm'),
            click: function(){
              $.get('./?obj=user_ctrl&method=deleteOne&id=' + id, function(data){
                core.message(data.text, data.status);
                $('#modal').modal('hide');
                layout.tabs.reloadActive(); 
              },
              'json');
            }
          },
          {
            text: core.tr('cancel'),
            action: 'close'
          }
        ]
      }, 'modal');
    }
    
  };