/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2011
 * @license			See file LICENSE distributed with this code
 * @since			10/mag/2011
 * @uses			core.message
 * 
 * 
 */

function formControl(el, options){
	
	// element
	var $this = $(el);
	
	// array filled with errors
	var wrongEl = [];
	
	//default settings
	var settings = {
			addChange	: true,
			checkOnSubmit: true,
			ajaxURL		: '',	// server must reply (text) 'error' or 'success'
			submitURL 	: '', 	//server must reply json with obj.status: 'success' or 'error'
			success 	: function(){},	// action to perform on success!
			msg : {
				not_empty		: core.tr('not_empty'),
				int				: core.tr('int'),
				email			: core.tr('email_check'),
				no_dupl			: core.tr('no_dupl'),
				range			: core.tr('range'),
				regex			: core.tr('regex'),
				no_data_to_save	: core.tr('no_data_to_save'),
				errors_in_form 	: core.tr('errors_in_form'),
				ajax_error 		: core.tr('ajax_error'),
				no_rules_for	: core.tr('no_rules_for')
			}
	};
	
	// extend default settings with options
	if (options){
		settings = $.extend(settings, options);
	}
	
	// add changed attribute to form inputs on change event
	if ( settings.addChange ) {
		$this.find (':input').change(function(){
			$(this).attr('changed', 'auto');
		});
	}
	
	// public method observe
	this.observe = function(){
		
		// reset wrongEl array
		wrongEl = [];

		$this.find(':input[check]').change(function(){
			removeError(this);
			var el = $(this);

			if ( el.attr('check') && el.attr('check') != 'undefined' ) {
				var thisCheckTypes = el.attr('check').split(' ');

				$.each( thisCheckTypes, function(index, type){
					checkInput(el, type, settings);
				});
			}
		});
		
		return this;
	};
	
	// public method: Checks the form for errors;
	this.check = function(){
		
		// reset wrongEl array
		wrongEl = [];
		
		// remove previous errors from form 
		removeError();
		
		var checkTypes = [ 'not_empty', 'int', 'email', 'no_dupl', 'range', 'regex' ];

		$.each(checkTypes, function(index, id){
			// no duplicate is checked only on keyup!
			if (id != 'no_dupl'){
				$this.find('[check~="' + id + '"]').each(function(index, el){
					checkInput($(el), id);
				});
			}
		});
		
		return this;
	};
	
	/**
	 * Sends form with controles:
	 *	checks if the wrongEl contains errors; if yes form will not be send; an error message will appear
	 *	only inputs with 'changed' tags will be sent!  
	 */
	this.send = function (all) {
		
		// checks for pesent errors
		if ( wrongEl.length > 0 ) {
		
			core.message(settings.msg.errors_in_form, 'error');
			
		} else {
			
			if (all){
				
				var ser = $this.serialize();
				
			} else {
				
				// disable unchanged inputs!
				var not_changed = $this.find(':input:not([changed])');
				
				not_changed.attr('disabled','disabled');
		
				// serialize changed inputs
				var ser = $this.serialize();
		
				// re-enable inputs
				not_changed.removeAttr('disabled');
			}
			
			
			if ( !ser ) {
				
				core.message(settings.msg.no_data_to_save, 'error');
				
			} else {
				
				$.post(
						settings.submitURL,
						ser,
						function(data){
							
							if (data.status == 'success'){
								
								// remove changed tags if there is a successful response from server!
								$this.find(':input[changed="auto"]').removeAttr('changed');
	
								core.message(data.verbose, 'success');
								
								settings.success(data);
								
							} else if (data.status == 'error') {
								
								core.message(data.verbose, 'error');
								
							} else {
								
								core.message(data.verbose, 'error');
							}
						},
						'json'
				);
			}
		}
		
		return this;
	};
	
	this.option = function(key, value){
		if(value){
			settings[key] = value;
			return this;
		} else {
			return settings[key];
		}
	};
	
	// private method
	var styleError = function(input, checkType){
		if ( !input.hasClass('notValid') ) {
			$('<span />')
				.addClass('notValid')
				.html('*' + checkType)
				.insertAfter(input).position({
					my: 'left center',
					at: 'right-10 center',
					of: input
				});
		} else {
			input.next('span.notValid').append('<br />' + checkType);
		}
		input.addClass('notValid');
	};
	
	
	// private method: Removes error style (class) and text from form or from element
	var removeError = function(el){
		
		if ( el ) {
			$(el).next('span.notValid').remove();
			$(el).removeClass('notValid');
			
		} else {
			
			$this.find('span.notValid').remove();
			$this.find(':input.notValid').removeClass('notValid');
			
		}
	};
	
	
	
	/**
	 *	Main check function:
	 *		checks input value using checkType
	 *		if check is not successful input will be send to wrongEl array and input will be marked with error text and style 
	 */
	var checkInput = function(input, checkType){
		

		var val = input.val();
		
		switch ( checkType ) {

			case 'int':
				if ( isNaN(val) ) {
					styleError(input, settings.msg[checkType]);
					wrongEl.push(input);
				}
				break;
			case 'email':
				var emailPattern = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}$/;
				
				if ( val!= '' && !emailPattern.test(val) ) {
					styleError(input, settings.msg[checkType]);
					wrongEl.push(input);
				}
				break;
			case 'not_empty':
				if ( val == '' ) {
					styleError(input, settings.msg[checkType]);
					wrongEl.push(input);
				}
				break;
			case 'no_dupl':
				if (val){
					$.ajax({
						url: settings.ajaxURL + '&fld=' + input.attr('name') + '&val=' + val,
						complete: function(data){
							if (data.responseText == 'error') {
								styleError(input, settings.msg[checkType]);
								wrongEl.push(input);
							}
						},
						error: function(data){
							styleError(input, settings.msg.ajax_error);
							core.message(settings.msg.ajax_error, 'error');
						}
					});
				}
				break;
			case 'range':
				var min = parseInt(input.attr('min'));
				var max = parseInt(input.attr('max'));
				
				val = parseInt(val);

				if ( val < min || val > max  || isNaN(val)) {
					styleError(input, settings.msg[checkType] + ' (' + min + ' - ' + max + ')');
					wrongEl.push(input);
				}
				break;
				
			case 'regex':
				var mypattern = input.attr('mypattern');
				var pattern = new RegExp (mypattern);
				if (!pattern.test(val)) {
					styleError(input, settings.msg[checkType] + ' (' + mypattern+ ')');
					wrongEl.push(input);
				}
				break;
				
			default:
				console.log(settings.msg.no_rules_for + ' ' + checkType);
				break;

		}
	};
	
	return this;
}

