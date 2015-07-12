/*
 * copyright BraDypUS 
 */
var debug={lastSize:0,init:function(){core.open({obj:'debug_ctrl',method:'read',loaded:function(){debug.updateLog();setInterval('debug.updateLog()',5000);},title:core.tr('error_log')});},updateLog:function(){var invert=$('#log_invert:checked').length;if($('#delete_log:checked').length)
{$.getJSON('controller.php?&obj=debug_ctrl&method=delete',function(data){core.message(data.text,data.status);$('#delete_log').attr('checked',false);});return;}
if($('#log_live:checked').length===1){$('#log_going_on').show();core.getJSON('debug_ctrl','ajax',{'lastSize':debug.lastSize,'grep':$('#log_grep').val(),'invert':invert},false,function(data){debug.lastSize=data.size;$.each(data.data,function(key,value){$("#log_results").append(''+value+'<br/>');});});}else{$('#log_going_on').hide();}},toggleLog:function(){if($('#log_live').is(':checked')){$('#log_live').attr('checked',false);}else{$('#log_live').attr('checked',true);}}};