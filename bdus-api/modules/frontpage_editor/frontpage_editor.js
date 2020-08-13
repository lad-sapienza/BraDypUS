/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 */

var frontpage_editor = {
		init: function(){
			$.get('./?obj=frontpage_editor_ctrl&method=get_content', function(data){
				var html = '<p class="text-error">'
						+ '<i class="glyphicon glyphicon-warning-sign"></i> <strong>' + core.tr('attention') + ':</strong> ' + core.tr('no_php_allowed') + '</p>'
						+ '<textarea style="width: 98%; height: 300px">' + data + '</textarea>';
				var div = core.open({
				html:html,
				title: core.tr('edit_frontpage'),
				buttons:[
				         {
				        	 text: core.tr('save'),
				        	 click: function(){
								var val = $('#modal textarea').val();
								
								core.getHTML('frontpage_editor_ctrl', 'save_content', false, { "text": val }, data=>{
									if (!data || data == ''){
										core.message(core.tr('ok_edit_file'), 'success');
									} else {
										core.message(core.tr('error_edit_file'), 'error');
									}
								});
				        	 }
				         },
				         {
				        	 text: core.tr('close'),
				        	 click: 'close'
				         }
				         ]
				}, 'modal');
			});
		}
};
