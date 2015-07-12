/*
 * copyright BraDypUS 
 */
var empty_cache={init:function(){core.open({html:core.tr('confirm_empty_cache'),title:core.tr('empty_cache'),buttons:[{text:core.tr('empty_cache'),click:function(){$('#modal .modal-body').html(core.loading+'<h3>'+core.tr('empty_cache_waiting')+'</h3>');core.getJSON('empty_cache_ctrl','doEmpty',false,false,function(data){core.message(data.text,data.status);if(data.status=='success'){$('#modal').modal('hide');}});}},{text:core.tr('cancel'),action:'close'}]},'modal');}};