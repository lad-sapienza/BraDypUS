/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 */

var config = {

	init: function(){
		
		core.open({
			title: core.tr('sys_config'),
			obj: 'config_ctrl',
			method: 'home',
		});
	},
	viewAppProperties: (uid) => {
		$.get(`./?obj=config_ctrl&method=app_properties`, html => {
			$('#' + uid + ' .edit-column').html(html);
			$('#' + uid +' .field-list-column').html('').removeClass('col-sm-3');
		})
	},
	viewTbproperties: function(tb, uid){
		$.get(`./?obj=config_ctrl&method=table_properties&tb=${tb}`, html => {
			$('#' + uid + ' .edit-column').html(html);
			$('#' + uid + ' .field-list-column').html('').removeClass('col-sm-3');
		})
	},
	viewFldList: function(tb, uid){
		$.get(`./?obj=config_ctrl&method=fld_list&tb=${tb}`, html => {
			$('#' + uid + ' .field-list-column').html(html);
			$('#' + uid + ' .field-list-column').addClass('col-sm-3');
			$('#' + uid + ' .edit-column').html('');
		})
	},
	viewFldPropertied: function(tb, fld, thisel){
		const toEl = $(thisel).parents('.main').children('.edit-column');
		$.get(`./?obj=config_ctrl&method=field_properties&tb=${tb}&fld=${fld}`, html => {
			toEl.html(html);
		})
	},
	saveTbData: function(form, tb){
		core.getJSON('config_ctrl', 'save_tb_data', false, form.serializeArray(), function(data){
			  core.message(data.text, data.status);
			  if (data.status == 'success'){
				  config.viewTbproperties(form.parent('.edit-column'), tb);
			  }
		  });
	},
	saveFldProperties: (form, tb, fld ) => {
		core.getJSON('config_ctrl', 'save_fld_properties', false, form.serializeArray(), function(data){
			core.message(data.text, data.status);
			if (data.status == 'success'){
				config.viewFldPropertied(tb, fld, form);
			}
		});
	},
	saveAppProperties: (form) => {
		core.getJSON('config_ctrl', 'save_app_properties', false, form.serializeArray(), function(data){
			core.message(data.text, data.status);
		});
	}

};