var user = {
		
		init: function(id, dontreload){
			if (id){
				user.editUI(id, dontreload);
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
		
		editUI: function(id, dontreload){
			core.open({
				obj:'user_ctrl',
				method:'editUI',
				param: [id],
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
								 
								 core.getJSON('user_ctrl', 'edit', false, post_data, function(data){
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
		erase: function(id){
			core.open({
				html: core.tr('confirm_erase_user'),
				buttons:[
				         {
				        	 text: core.tr('confirm'),
							 click: function(){
								 $.get('controller.php?obj=user_ctrl&method=erase&param[]=' + id, function(data){
									 core.message(data.message, data.type);
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