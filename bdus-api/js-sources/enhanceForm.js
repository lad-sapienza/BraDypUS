/**
* This files collects diferente jquery widges to enhance form elements, and a function to enhance a given form
* Actually the following widgets are supported:
*   combobox
*   multiselect
*
* @author      Julian Bogdani <jbogdani@gmail.com>
* @copyright    BraDypUS, Julian Bogdani <jbogdani@gmail.com>
* @license      See file LICENSE distributed with this code
* @since      23/oct/2011
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

  getAjaxData: function(el){
    var datalist = $('datalist[for="' + el.attr('id') + '"]').first(),
      context = el.data('context'),
      att = el.data('att'),
      tags = el.data('tags');

    // Return empty object if no data attribute are found
    if(typeof context === 'undefined' || typeof att === 'undefined'){
      return {};
    }

    return {
      width: '100%',
      tags: (typeof tags !== 'undefined'),
      createTag: function (params) {
        return {
          id: params.term,
          val: params.term,
          newOption: true
        };
      },
      ajax: {
        url: 'controller.php?obj=menuValues_ctrl&method=getValuesUrl&context=' + context + '&att=' + att,
        dataType: 'json',
        delay: 250,
        tokenSeparators: [';'],
        data: function (p) {
          return {
            q: p.term,
            p: p.page
          };
        },
        processResults: function(data, p){
          data.data.unshift({"id": '', "val": ''}),
          p.page = p.page || 1;
          return {
            results: data.data,
            pagination: {
              more: (p.page * 30) < data.tot
            }
          };
        },
        cache: true
      },

      escapeMarkup:        function (markup) { return markup; },
      templateResult:      function (a) { return a.val; },
      templateSelection:  function (a) { return a.hasOwnProperty('val') ? a.val : a.text; },
      minimumInputLength:  0
    };
  },

  multiselect: function(el, destroy)
  {
    if ($(el).data('select2') && destroy){
      $(el).select2('destroy');
    } else {
      $(el).select2(
        $.extend({}, {
          separator: ';',
          tokenSeparators: [';']
        }, enhance.getAjaxData($(el)) )
      ).on('change', function(){
        $(this).attr('changed', 'auto');
      });
    }
  },

  combobox: function(el, destroy){

    if($(el).data('select2') && destroy){
      $(el).select2('destroy');
    } else {
      $(el).select2(
        $.extend({}, {
          data: enhance.getData($(el), true)
        }, enhance.getAjaxData($(el)))
      ).on('change', function(){
        $(this).attr('changed', 'auto');
      });
    }

  },

  select: function(el, destroy){
    if($(el).data('select2') && destroy){
      $(el).select2('destroy');
    } else {
      $(el).select2(
        enhance.getAjaxData($(el))
      ).on('change', function(){
        $(this).attr('changed', 'auto');
      });
    }
  },

  slider: function(el, destroy){
    if ($(el).data('select2') && destroy){
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
    if($(el).hasClass('combobox')) {
      enhance.combobox(el, destroy);
    } else if ($(el).hasClass('multiselect')) {
      enhance.multiselect(el, destroy);
    } else if ($(el).hasClass('slider')) {
      enhance.slider(el, destroy);
    } else if ($(el).hasClass('date')) {
      if (destroy){
        $(el).datepicker('remove');
      } else {
        $(el).datepicker({ format: enhance.dateFormat });
      }
    } else if ($(el).is('select')) {
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
