/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 */

var app_data_editor = {
		init: function(){
			core.open({
				obj: 'app_data_editor_ctrl',
				method: 'getInfo',
				'json': true,
				title: core.tr('app_data_editor'),
				buttons:[
				         {
				        	 text: core.tr('save'),
				        	 click: function(){
				        		 $.post('controller.php?obj=app_data_editor_ctrl&method=save', $('#modal form').serialize(), function(data){
				        			 core.message(data.text, data.status);
				        			 if (data.status == 'success'){
				        				 api.requireRestart();
				        			 }
				        		 }, 'json');
				        	 }
				         },
				         {
				        	 text: core.tr('close'),
				        	 action: 'close'
				         }
				         ]
				
			}, 'modal');
		}
};
