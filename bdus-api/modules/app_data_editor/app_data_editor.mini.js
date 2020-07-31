/*
 * copyright BraDypUS 
 */
var app_data_editor={init:function(){core.open({obj:'app_data_editor_ctrl',method:'getInfo','json':true,title:core.tr('app_data_editor'),buttons:[{text:core.tr('save'),click:function(){$.post('./?obj=app_data_editor_ctrl&method=save',$('#modal form').serialize(),function(data){core.message(data.text,data.status);if(data.status=='success'){api.requireRestart();}},'json');}},{text:core.tr('close'),action:'close'}]},'modal');}};