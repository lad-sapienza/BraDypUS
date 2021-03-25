/**
* @author			Julian Bogdani <jbogdani@gmail.com>
* @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
* @license			See file LICENSE distributed with this code
* @since			10/mag/2011
* @uses			core.message
*
*
*/

function formControls(form, options){
  
  // element
  var $form = $(form);
  
  // array filled with errors
  var wrongEl = {};
  
  //default settings
  var settings = {
    checkOnSubmit: true,
    validationURL : '',         // server must reply (text) 'error' or 'success'
    submitURL 	: '',           //server must reply json with obj.status: 'success' or 'error'
    success 	: function(){},   // action to perform on success!
    msg : {
      not_empty       : core.tr('not_empty'),
      int             : core.tr('int'),
      email           : core.tr('email_check'),
      no_dupl         : core.tr('no_dupl'),
      range           : core.tr('range'),
      regex           : core.tr('regex'),
      valid_wkt       : core.tr('valid_wkt'),
      no_data_to_save : core.tr('no_data_to_save'),
      errors_in_form  : core.tr('errors_in_form'),
      ajax_error      : core.tr('ajax_error'),
      no_rules_for    : core.tr('no_rules_for')
    }
  };
  
  // extend default settings with options
  if (options){
    settings = $.extend(settings, options);
  }

  const onSuccessCb = (input_el, type) => {
    
    const input_id = input_el.attr('id');

    if (Array.isArray(wrongEl[input_id]) && wrongEl[input_id].indexOf(type) > -1){
      wrongEl[input_id].splice(wrongEl[input_id].indexOf(type), 1);
      if (wrongEl[input_id].length < 1){
        delete wrongEl[input_id];
        removeError(input_el);
      }
    }

    if (typeof wrongEl[input_el] === 'undefined'){
      input_el.attr('changed', 'auto');
      const id_name = input_el.data('changeonchange');
      if (id_name){
        $(`:input[name="${id_name}"]`).attr('changed','auto');
      }
    }
  };

  const onErrorCb = (input_el, type) => {
    styleError(input_el, settings.msg[type]);
    const input_id = input_el.attr('id');
    if (Array.isArray(wrongEl[input_id])){
      wrongEl[input_id].push(type);
    } else {
      wrongEl[input_id] = [type];
    }
    
    // Remove change attribute to single input
    input_el.removeAttr('changed');
  };



  // add changed attribute to form inputs on change event
  $form.on('change', ':input', function(){

    const input = $(this);
    const available_checks = input.attr('check');

    // Input has validation rules
    if (typeof available_checks !== 'undefined'){
      available_checks.split(" ").map( type => {
        checkInput( input, type );
      });
    } else {
      input.attr('changed', 'auto');
    }
  });

  
  // public method: Checks the form for errors;
  const checkBeforeSubmit = function(){
    
    // no duplicate is checked only on keyup!
    var checkTypes = [ 
      'not_empty', 
      'int', 
      'email', 
      /* 'no_dupl', */ 
      'range', 
      'regex'
      /* 'valid_wkt' */ 
    ];
    
    $.each(checkTypes, function(index, id){
      $form.find('[check~="' + id + '"]').each(function(index, el){

        // Ignore not checked values
        if (!$(el).attr('changed')){
          return;
        }
        // not_empty is always validated in core, but not in plugins
        if (id === 'not_empty' && !$(el).data('changeonchange')){
          checkInput($(el), id);
        // unless plugin data is inserted
        } else if (id === 'not_empty' && $(el).data('changeonchange') && $(`:input[name="${$(el).data('changeonchange')}"]`).attr('changed')) {
          checkInput($(el), id);
        // other controls only on changed inputs
        } else if ($(el).data('changed')){
          checkInput($(el), id);
        }
      });
    });
  };
  
  /**
  * Sends form with controles:
  *	checks if the wrongEl contains errors; if yes form will not be send; an error message will appear
  *	only inputs with 'changed' tags will be sent!
  */
  this.send = function (all) {
    
    checkBeforeSubmit();
    // Stop only if errors are in not changed objects
    let stop;
    for (const el_id in wrongEl) {
      if ($(`#${el_id}`).attr('changed')){
        stop = true;
      }
    }

    // checks for pesent errors
    if ( stop ) {
      core.message(settings.msg.errors_in_form, 'error');
    } else {
      
      var ser;
      
      if (all){
        ser = $form.serialize();
      } else {
        // disable unchanged inputs!
        var not_changed = $form.find(':input:not([changed])');
        not_changed.attr('disabled','disabled');
        // serialize changed inputs
        ser = $form.serialize();
        // re-enable inputs
        not_changed.removeAttr('disabled');
      }
      
      if ( !ser ) {
        core.message(settings.msg.no_data_to_save, 'error');
      } else {
        $.post( settings.submitURL, ser, function(data){
          if (data.status === 'success'){
            // remove changed tags if there is a successful response from server!
            $form.find(':input[changed="auto"]').removeAttr('changed');
            core.message(data.verbose, 'success');
            settings.success(data);
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
  const styleError = function(input, checkType){
    if ( !input.hasClass('notValid') ) {
      $('<div />')
        .addClass('notValid')
        .html('*' + checkType)
        .insertAfter(input);
    } else {
      input.next('div.notValid').append('<br />' + checkType);
    }
    input.addClass('notValid');
  };
  
  // private method: Removes error style (class) and text from form or from element
  var removeError = function(el){
    $(el).next('div.notValid').remove();
    $(el).removeClass('notValid');
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
          onErrorCb(input, checkType);
        } else {
          onSuccessCb(input, checkType);
        }
      break;
      case 'email':
        var emailPattern = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}$/;
        
        if ( val !== '' && !emailPattern.test(val) ) {
          onErrorCb(input, checkType)
        } else {
          onSuccessCb(input, checkType);
        }
        break;
        case 'not_empty':
        if ( val === '' ) {
          onErrorCb(input, checkType)
        } else {
          onSuccessCb(input, checkType);
        }
      break;
      case 'no_dupl':
        if (val){
          $.ajax({
            url: settings.validationURL + '&type=duplicates&fld=' + input.attr('name') + '&val=' + val,
            complete: function(data){
              if (data.responseText === 'error') {
                onErrorCb(input, checkType)
              } else {
                onSuccessCb(input, checkType);
              }
            },
            error: function(data){
              styleError(input, settings.msg.ajax_error);
              core.message(settings.msg.ajax_error, 'error');
            }
          });
        }
      break;
      case 'valid_wkt':
        if (val){
          $.ajax({
            url: settings.validationURL + '&type=wkt&val=' + val,
            complete: function(data){
              if (data.responseText === 'error') {
                onErrorCb(input, checkType)
              } else {
                onSuccessCb(input, checkType);
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
          onErrorCb(input, checkType)
        } else {
          onSuccessCb(input, checkType);
        }
      break;
      
      case 'regex':
        var mypattern = input.attr('mypattern');
        var pattern = new RegExp (mypattern);
        if (val && !pattern.test(val)) {
          onErrorCb(input, checkType)
        } else {
          onSuccessCb(input, checkType);
        }
      break;
      
      default:
        console.log(settings.msg.no_rules_for + ' ' + checkType);
      break;
    }
  };
  return this;
}
