/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 */

var vocabularies = {
		init: function(){

			core.open({
				obj: 'vocabularies_ctrl',
				method: 'list',
				title: core.tr('vocabulary_mng'),
				loaded: (html) => {
					
					html.find('.sortable').each( (index, element)=>{
						new Sortable(element, {
							animation: 150,
							ghostClass: 'active',
							onEnd: function (evt) {
								const sortArray = this.toArray();
								core.runAndRespond('vocabularies_ctrl', 'sort', { sort: sortArray });
							}
						});
					});
					// Add listener to vocs, to open own items
					html.find('a.voc').on('click', function(){
						$(this).parents('li').find('a').toggleClass('active');
						$(this).next('ul').toggle();
					});

					// Add listener to item edit button
					html.find('.edit_def').on('click', function(){
						var li = $(this).parents('li:eq(0)');
						vocabularies.edit(li.data('id'), li.data('text'), function(val){
							li.data('text', val);
							li.find('.def').text(val);
						});
					});

					// Add listener to item delete button
					html.find('.delete_def').on('click', function(){
						var li = $(this).parents('li:eq(0)');
						vocabularies.erase(li.data('id'), function(){
							if (li.siblings().length === 1 ){
								li. parent('ul').parent('li').remove();
							} else {
								li.remove();
							}
						})
					});
					// Add listener to add new vocabulary button
					html.find('.add_voc').on('click', () => {
						vocabularies.add_new($(this).data('voc'), function(){
							layout.tabs.reloadActive();
						});
					});
				}
			});
		},

		edit: function(id, text, success){
			var html = `<input value="${text}" type="text" class="form-control">`;
			core.open({
				title: core.tr('edit_def'),
				html: html,
				buttons:[
				         {
				        	 text: core.tr('save'),
				        	 click: function(){
								 var val = $('#modal input').val();
								 core.getJSON('vocabularies_ctrl', 'edit', { id: id, val: val }, false, function(data){
							         core.message(data.text, data.status);
							         if (data.status === 'success'){
							        	 if (success){
							        		 success(val);
							        	 }
								     }

				        		 });
				        		 $('#modal').modal('hide');
				        	 }
				         },
				         {
				        	 text: core.tr('close'),
				        	 action: 'close'
				         }
				         ]
			}, 'modal');
		},

		erase: function(id, success){
			core.open({
				title: core.tr('attention'),
				html: '<h3 class="text-error">' + core.tr('confirm_delete_def') + '</h3>',
				buttons:[
				        {
				        	text: core.tr('erase'),
				        	click: function(){
				        		 core.getJSON('vocabularies_ctrl', 'erase', { id: id }, false, function(data){
							         core.message(data.text, data.status);
							         if (data.status == 'success'){
							        	 if (success){
							        		 success();
							        	 }
								     }
					        	 });

				        		 $('#modal').modal('hide');
				        	}
				        },
				        {
				        	 text: core.tr('close'),
				        	 action: 'close'
				         }
				        ]
			}, 'modal');
		},

		/**
		 * Adds a new voc, closes dialog and reopens it (updated)
		 * @param dialog
		 */
		add_new: function(voc, success){
			core.open({
				title: core.tr( voc ? 'new_def' : 'new_voc'),
				obj: 'vocabularies_ctrl',
				method: 'add_new_form',
				param: {voc: voc},
				buttons:[
				         {
				        	text: core.tr('add'),
				        	click: function(){
				        		var myvoc = $('#voc').val(), mydef = $('#def').val();
				        		 if (!myvoc || !mydef){
				        			 core.message(core.tr('voc_def_required'), 'error');
				        		 } else {
				        			 core.getJSON('vocabularies_ctrl', 'add', { voc: myvoc, def:mydef }, false, function(data){
				        				 if (data.status === 'success'){
				        					 $('#def').val('');
				        					 if (success){
				        						 success();
				        					 }
				        				 }
				        				 core.message(data.text, data.status);
				        			 });
				        		 }
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
