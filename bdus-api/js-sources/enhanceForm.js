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
const enhance = {
  getData: function(el){
    const datalist = $('datalist[for="' + el.attr('id') + '"]').first();
    let data = [];

    datalist.children( "option" ).map(function() {
      const text = $(this).text();
      const value = $(this).val() ? $(this).val(): text;

      data.push({
        id: value, 
        text: text
      });
    });
    return data;
  },

  getAjaxData: el => {
    const context = el.data('context');
    const att = el.data('att');
    const tags = el.data('tags');

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
        url: './?obj=menuValues_ctrl&method=getValuesUrl&context=' + context + '&att=' + att,
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

      escapeMarkup:        markup =>  markup,
      templateResult:      a => { return a.val; },
      templateSelection:   a => { return a.hasOwnProperty('val') ? a.val : a.text; },
      minimumInputLength:  0
    };
  },

  multiselect: (el, destroy) => {
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

  combobox: (el, destroy) => {

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

  select: (el, destroy) => {
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

  pimpEl: (el, destroy) => {
    if($(el).hasClass('combobox')) {
      enhance.combobox(el, destroy);
    } else if ($(el).hasClass('multiselect')) {
      enhance.multiselect(el, destroy);
    } else if ($(el).is('select')) {
      enhance.select(el, destroy);
    }
  },
  // form function: takes form element and applies to all input elements all available widges
  form: form => {
    $(form).find(':input').each(function(i, el){
      enhance.pimpEl(el);
    });
  }
};
