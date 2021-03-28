/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 */

var chart = {
		init: function(tb, obj_encoded){
			
			if (tb === 'showChart' && obj_encoded){
				chart.showChartById(obj_encoded);
			} else if (tb){
				chart.showBuilder(tb, obj_encoded);
			} else {
				chart.showSaved();
			}
		},
		
		editChartGUI: function(id){
			
			core.open({
				'obj': 'chart_ctrl',
				'method': 'edit_form',
				'param': {id: id},
				'title': core.tr('edit_chart')
			});
		},
		
		editChart: function(id, name, text){
			core.getJSON('chart_ctrl', 'update_chart', false, {'id':id, 'name':name, 'text':text}, function(data){
				core.message(data.text, data.status);
			});
		},
		
		buildFromParams: function(form_data){
			core.open({
				 obj: 'chart_ctrl',
				 method: 'process_chart_data',
				 title: core.tr('chart'),
				 post: form_data
			 });
		},
		
		/**
		 * Opens tab with table containing liost of all saved charts
		 */
		showSaved: function(){
			core.open({
				obj: 'chart_ctrl',
				method: 'show_all_charts',
				title: core.tr('saved_charts')
			});
		},
		
		
		showChartById: function(id){
			core.open({
				obj: 'chart_ctrl',
				method: 'display_chart',
				title: core.tr('chart'),
				param: {id: id}
			});
		},
		
		
		saveAs: function(query_text){
			core.open({
				html: `<div>
				<label>${core.tr('chart_name')}</label>
				<br>
				<input type="text" class="chart_name form-control">
				</div>`
				,
				title: core.tr('save_chart_as'),
				buttons:[
				         {
				        	 text: core.tr('save'),
				        	 click: function(){
				        		 core.getJSON('chart_ctrl', 'save_chart_as', false, {query_text:query_text, name:$('#modal input.chart_name').val()}, function(data){
				        			 core.message(data.text, data.status);
				        			 if (data.status === 'success'){
				        				 $('#modal').modal('hide');
				        			 }
				        		 });
				        	 }
				         },
				         {
				        	 text: core.tr('cancel'),
				        	 action: 'close'
				         }
				         ]
			}, 'modal');
		},
		
		showBuilder: function(tb, obj_encoded){
			core.open({
				title: core.tr('build_chart'),
				obj: 'chart_ctrl',
				method: 'show_chart_builder',
				param: {tb: tb, obj_encoded: obj_encoded}
			});
			
		}
};