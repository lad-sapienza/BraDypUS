/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 */

var translate = {
		init: function(lang){
			
			core.open({
				obj: 'translate_ctrl',
				method: 'showList',
				param:[lang],
				title: core.tr('system_translate'),
				unique:true
			});
		},
		
		addLang: function(id){
			core.open({
				html: $('<input />').attr({type:'text', maxlength:2}).addClass('newLang'),
				title: core.tr('new_lang_code_two_digits'),
				buttons: [{
					text: core.tr('save'),
					click: function(){
						var lang = $('#modal').find('input.newLang').val();
						if(!lang || lang.length !== 2){
							core.message(core.tr('lang_lenth_must_be_two'), 'error');
						} else {
							$('#modal').modal('hide');
							core.getJSON('translate_ctrl', 'newLang', [lang], false, function(data){
								core.message(data.text, data.status);
								if (data.status == 'success'){
									translate.init();
								}
							});
						}
					}
				},
				{
					text: core.tr('close'),
					action: 'close'
				}]
			}, 'modal');
		}
};

