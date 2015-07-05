/*
 * copyright BraDypUS 
 * Created: 1436102710.3699
*/
var myHistory={init:function(){$.get('controller.php?obj=myHistory_ctrl&method=show_all',function(data){if(data&&data!=='<pre></pre>'){core.open({html:data,title:core.tr('history'),buttons:[{text:core.tr('erase'),click:function(){$this=$(this);core.getJSON('myHistory_ctrl','erase',false,false,function(data){core.message(data.text,data.status);if(data.status=='success'){layout.dialog.close();}});}},{text:core.tr('close'),click:'close'}]},'modal');}else{core.message(core.tr('history_is_empty'));}});}};