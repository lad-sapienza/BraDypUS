/**
 * This files collects diferente jquery widges to enhance form elements, and a function to enhance a given form
 * Actually the following widgets are supported:
 * 	combobox
 * 	multiselect
 * 
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2011
 * @license			See file LICENSE distributed with this code
 * @since			23/oct/2011
 */



/**
 * Applies widgets to single / all form elements
 * @example: enhance.form($('#formId'));
 * @example: enhance.element($('#elId'));
 */
var enhance = {
	//predefinied date format
	dateFormat : 'yyyy-mm-dd',
	
	getData: function(el){
		var datalist = $('datalist[for="' + el.attr('id') + '"]').first(),
		data = [];

		datalist.children( "option" ).map(function() {
			var text = $(this).text(),
			value = $(this).val() ? $(this).val(): text;
			
			data.push({id: value, text: text});
		});
		return data;
	},
	
	multiselect: function(el, destroy)
	{
		if (destroy){
			$(el).select2('destroy');
		} else {
			$(el).select2({
				tags: enhance.getData($(el)),
				separator: ';',
				tokenSeparators: [';'],
				width: '100%'
			}).on('change', function(){
				$(this).attr('changed', 'auto');
			});
		}
	},
	
	combobox: function(el, destroy){
		if(destroy){
			$(el).select2('destroy');
		} else {
			$(el).select2({
				data: enhance.getData($(el)),
				width: '100%',
				createSearchChoice: function(term){
					return {id:term, text:term};
				},
				initSelection:function(el, callback){
					var value = $(el).val();
					callback({id: value, text:value});
				}
			}).on('change', function(){
				$(this).attr('changed', 'auto');
			});
		}
		
	},
	
	select: function(el, destroy){
		if(destroy){
			$(el).select2('destroy');
		} else {
			$(el).select2({
				width: '100%'
			}).on('change', function(){
				$(this).attr('changed', 'auto');
			});
		}
	},
	
	slider: function(el, destroy){
		if (destroy){
			$(el).slider('destroy');
		} else {
			var min = $(el).attr('min') ? parseInt($(el).attr('min')) : 0,
			max = $(el).attr('max') ? parseInt($(el).attr('max')) : 10,
			value = $(el).val() ? parseInt($(el).val()) : min;
			$(el).slider({
				'value': value,
				'min': min,
				'max': max,
			}).on('slideStop', function(){
				$(el).attr('changed', 'auto');
			});
		}
	},
	
	pimpEl: function(el, destroy){
		if($(el).hasClass('combobox'))
		{
			enhance.combobox(el, destroy);
		}
		else if ($(el).hasClass('multiselect'))
		{
			enhance.multiselect(el, destroy);
		}
		else if ($(el).hasClass('slider'))
		{
			enhance.slider(el, destroy);
		}
		else if ($(el).hasClass('date'))
		{
			if (destroy){
				$(el).datepicker('destory');
			} else {
				$(el).datepicker({ format: enhance.dateFormat });
			}
		}
		else if ($(el).is('select'))
		{
			enhance.select(el, destroy);
		}
	},
	// form function: takes form element and applies to all input elements all available widges
	form: function(form){
		$(form).find(':input').each(function(i, el){
			enhance.pimpEl(el);
		});
	}
};