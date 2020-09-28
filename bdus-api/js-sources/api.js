/**
 * @author      Julian Bogdani <jbogdani@gmail.com>
 * @copyright    BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license      See file LICENSE distributed with this code
 * Main Javascript API
 * @type object
 */
var api = {
    requireRestart: function(message){
      const html =  `${ message ? `<h3>${ message }</h3>` : ''}
      <p class="lead text-warning">
        <i class="glyphicon glyphicon-warning-sign"></i>
        <strong>${core.tr('attention')}</strong>
        <br />
        ${core.tr('reload_sys_required')}
      </p>`;

      core.open({
        html: html,
        title: core.tr('attention'),
        buttons:[
            {
               text: core.tr('restart'),
               click: () => {
                 api.reloadApp();
               }
            },
            {
              text: core.tr('cancel'),
              action: 'close'
            }
            ]
      }, 'modal');

    },

    confirmSuperAdmPwd: function(success) {
      core.open({
        title: core.tr('cfg_security_check'),
        html: `<p class="lead text-danger">${core.tr('cfg_security_check_text')}</p>
        <hr>
        <form action="javascript:void(0)">
          <input type="password" class="adm_pwd form-control" placeholder="password">
          <button type="submit" style="display: none"></button>
        </form>`,
        loaded: () => {
          setTimeout( () => { 
            $('#modal input.adm_pwd').trigger('focus');
          }, 500 );
          $('#modal form').on('submit', ()=>{
            const pwd = $('#modal .adm_pwd').val();
              core.getJSON('confirm_super_adm_pwd_ctrl', 'check_pwd', false, {pwd: pwd}, data =>{
                core.message(data.text, data.status)
                if (data.status === 'success'){
                  $('#modal').modal('hide');
                  if (typeof success === 'function'){
                    success();
                  }
                }
              });
          });
        },
        buttons: [
          {
            text: core.tr('close'),
            action: 'close'
          },
          {
            addclass: 'btn-danger',
            text: core.tr('validate_password'),
            click: () => {
              $('#modal form').trigger('submit');
            }
          }
        ]
      }, 'modal');
    },

    reloadApp: function(clean){
      if (clean){
        window.location = './';
      } else{
        window.location.reload();
      }
    },

    /**
     * Shows logout dialog with logout confirmation text
     */
    logOut: function(){
      core.open({
        html: '<p class="text-warning"><i class="glyphicon glyphicon-exclamation-sign"></i> ' + core.tr('logout_confirm_body') + '</p>',
        title: core.tr('logout_confirm_title'),
        buttons: [
                {
                  text: core.tr('cancel'),
                  action: 'close'
                },
                {
                  text: core.tr('close_application'),
                  addclass: 'btn-primary',
                  click: function() {
                    $.get('./?obj=login_ctrl&method=out', function(data){
                      window.location = './';
                    });
                  }
              }
              ]
      }, 'modal');

    },

    /**
     * Opens tab and displays resutls of query
     * @param string tb        reference table
     * @param string url_data  GET data, usually query type
     * @param string title    tab's title
     * @param object post_data  Post data to use for query building
     * @returns {undefined}
     */
    showResults: function(tb, url_data, title, post_data){
      core.open({
        obj: 'record_ctrl',
        method: 'showResults',
        param: url_data ? url_data + '&tb=' + tb : false,
        post: post_data,
        title: title
      });
    },


      /**
       * Collects all function for record handling
       * @type object
       */
    record : {

      /**
       * Open matrix in new tab
       *
       * @param string tb    full table name
       * @param istring query    sql query
       * @returns {undefined}
       */
      showMatrix: function(tb, obj_encoded){
        core.open({
          title: core.tr('harris_matrix'),
          obj: 'matrix_ctrl',
          method: 'show',
          param: {tb: tb, obj_encoded: obj_encoded},
        });
      },

      formatId: function(id_arr, is_idField){
        return is_idField ? 'id_field[]=' + id_arr.join('&id_field[]=') : 'id[]=' + id_arr.join('&id[]=');
      },

        /**
         * Alias for api.record.read
         *
         * @param string tb table name
         * @param array id_arr array of ids ro show
         * @returns {undefined}
         */
      preview: function (tb, id_arr){
        api.record.read(tb, id_arr);
      },

        /**
         * Opens record in new tab in read mode
         *
         * @param string tb    full table name
         * @param array id_arr  array of ids or of id_fields to show
         * @param boolean is_idField if true id_arr are not id, but id_fields
         * @returns {undefined}
         */
      read: function (tb, id_arr, is_idField){

        core.open({
          obj: 'record_ctrl',
          method: 'show',
          param: 'tb=' + tb + /*'&' + this.formatId(id_arr, is_idField) +*/ '&a=read',
          post: this.formatId(id_arr, is_idField),
          title: core.tr('read') + ' (' + tb.replace(prefix, '') + ' > ' + (is_idField ? '' : '*') + id_arr[0] + ')'
        });
      },

        /**
         * Opens records in new tab in edit mode
         * @param string tb  full table name
         * @param array id_arr array of id to open
         * @returns {undefined}
         */
      edit: function (tb, id_arr){
        core.open({
          obj: 'record_ctrl',
          method: 'show',
          param: 'tb=' + tb + '&' + this.formatId(id_arr) + '&a=edit',
          title: core.tr('edit') + ' (' + tb.replace(prefix, '') + ')'
        });
      },

        /**
         * Asks confirmation and deletes record/s
         * @param string tb  full table name
         * @param array id_arr array of record ids to delete
         * @param object table_results table result object to update
         * @returns {undefined}
         */
      erase: function (tb, id_arr, table_results){
        if ( confirm ( core.tr('confirm_delete') ) ) {
          $.get('./?obj=record_ctrl&method=erase&' + this.formatId(id_arr) + '&tb=' + tb, function(data){
            core.message(data.text, data.status);
            if (table_results){
              table_results.fnDraw();
            } else {
              layout.tabs.closeActive();
            }
          }, 'json');
        }
      },

        /**
         * Opens tab with new record form
         * @param string tb  full table name
         * @returns {undefined}
         */
      add: function(tb){
        core.open({
          obj: 'record_ctrl',
          method: 'show',
          param: 'tb=' + tb + '&a=add_new',
          title: core.tr('edit') + ' (' + tb.replace(prefix, '') + ')'
        });
      },
        /**
         * Toogle selecting of records in result table
         * @param string tableId  Result table's id
         * @returns {undefined}
         */
      toogleSelect: function(tableId){
        if ($('#' + tableId + ' tbody tr:first').hasClass('row_selected')){
          $('#' + tableId + ' tbody tr').removeClass('row_selected');
        } else {
          $('#' + tableId + ' tbody tr').addClass('row_selected');
        }
      },

        /**
         * Method used n record/results to handle action on selected row in result table
         * @param string action Action to perform: preview|read|edit|erase|add
         * @param string tb  full table name
         * @param object table_results table result object to update when record is deleted (if action is erase)
         * @returns {Boolean}
         */
      actOn: function(action, tb, table_results){

        var id_arr = [];

        if (action != 'add'){
          // get selected rows
          var aTrs = table_results.fnGetNodes();
          for ( var i=0 ; i<aTrs.length ; i++ )
          {
            if ( $(aTrs[i]).hasClass('row_selected') )
            {
              id_arr.push( $(aTrs[i]).attr('id') );
            }
          }

          if (!id_arr[0]){
            core.message(core.tr('select_row_to_continue'), 'error');
            return;
          }
        }

        switch (action){
          case 'preview':
            this.preview(tb, id_arr);
            break;

          case 'read':
            this.read(tb, id_arr);
            break;

          case 'edit':
            this.edit(tb, id_arr);
            break;

          case 'erase':
            this.erase(tb, id_arr, table_results);
            break;

          case 'add':
            this.add(tb);
            break;

          default:
            return false;
        }
      }
    }, //end of api.record

  preview: function(img){
    var Img = new Image();
    Img.src = $(img).attr('src');
    var winH = $(window).height(),
    imgH = Img.height,
    H = (imgH > winH ? winH : imgH) -20;
    Img.height = (H-30);
    Img.title = $(img).attr('title');

    $(Img).addClass('img-responsive');
    core.open({
      html: $('<a />').attr('href', Img.src).attr('target', '_blank').append(Img),
      loaded: function(){
        $('.modal-body').css({'max-height': H});
      }
    }, 'modal');
  },

  query:{
    show: function(query_text){
      core.open({
        html: '<pre>' + decodeURIComponent(query_text.replace(/\+/g, ' ')) + '</pre>',
        title: core.tr('query_text'),
        buttons:[{
          text: core.tr('close'),
          action: 'close'
        }]
      }, 'modal');
    },

    Export: function(obj_encoded, tb){
      var html = '<select class="form-control export_format input-lg">' +
        '<option value="JSON">JSON</option>' +
        '<option value="XLS">XLS</option>' +
        '<option value="SQL">SQL (INSERT)</option>' +
        '<option value="CSV">CSV</option>' +
        '<option value="HTML">HTML</option>' +
        '<option value="XML">XML</option>' +
      '</select>';

      core.open({
        html: html,
        title: core.tr('export_select_format'),
        buttons: [
                  {
                    text: core.tr('continue'),
                    click: function(div){
                      layout.dialog.close(div);
                      $.post('./?obj=myExport_ctrl&method=doExport&tb=' + tb + '&format=' + $(div).find('select.export_format').val() + '&obj_encoded=' + obj_encoded, function(data){

                        core.message(data.text, data.status);

                        core.runMod('myExport');
                      }, 'json');
                    }
                  },
                  {
                    text: core.tr('cancel'),
                    action: 'close'
                  }
                  ]
      },'modal');

    },

    save: function(query_text, tb){

      var input =  $('<input />')
        .attr('type', 'text')
        .addClass('form-control')
        .val('query_' + new Date().getTime())
        .css('width', '90%');

      core.open({
        html: input,
        title: core.tr('name_for_query_to_save'),
        buttons:[
                 {
                   text: core.tr('save'),
                   click: function(dia){
                     if (input.val() === ''){
                       core.message(core.tr('query_name_is_required'), 'error');
                       input.focus();
                     } else {
                       core.getJSON(
                         'saved_queries_ctrl', 
                         'saveQuery', 
                         'tb=' + tb + '&name=' + input.val(),
                         {"query_object": query_text},
                         function(data){
                           core.message(data.text, data.status);
                         });
                       layout.dialog.close(dia);
                     }
                   }
                 },
                 {
                   text: core.tr('close'),
                   click: function(dia){
                     layout.dialog.close(dia);
                   }
                 }

                 ]
      }, 'modal');
    }

  },
  file: {
    show_gallery: function(tb, id){
      core.open({
        obj:'file_ctrl',
        method: 'gallery',
        param: {tb: tb, id: id},
        title: core.tr('file_gallery', [tb.replace(prefix, '') + '>' + id])
      });
    }
  },

  /**
   * Manages all linking (GUI) functions
   * @type object
   */
  link: {
    /**
     * Shows a modal dialog for selecting and adding links
     *
     * @param function successFunction callback function to execute on successfull action
     * @param function closeFunction  callback function to execute on errors
     * @param boolean select_one if true the select_one option will be added to the results table (only one link can be defined)
     * @param string def_tb  optional, if present the window will open the default table
     */
    add_ui: function(successFunction, closeFunction, select_one, def_tb){

      var div = $('<div />').append(
          $('<div />').addClass('btn-group'),
          $('<div />').addClass('fl_content'),
          $('<input />').addClass('curr_tb').addClass('form-control').attr('type', 'hidden')
          );

      $.get('./?obj=userlinks_ctrl&method=get_all_tables',function(data){
        if (data.status == 'success'){

          if (!def_tb){
            $.each(data.info, function(tb, label){
              $('<a />')
                .html(label)
                .addClass('btn btn-default')
                .click(function(){
                  div.find('.fl_content').load('./?obj=record_ctrl&method=showResults&noDblClick=1&tb=' + tb + '&type=all&noOpts=1' + (select_one ? '&select_one=true' : ''));
                  div.find('input.curr_tb').val(tb);
                })
                .appendTo(div.find('.btn-group'));
            });

          } else {
            div.find('.fl_content')
              .load('./?obj=record_ctrl&method=showResults&tb=' + def_tb + '&type=all&noOpts=1' + (select_one ? '&select_one=true' : ''));
            div.find('input.curr_tb').val(def_tb);
          }


          core.open({
                html: div,
                title:core.tr('new_link'),
                buttons: [
                          {
                            text:'<i class="glyphicon glyphicon-resize-small"></i> ' + core.tr('save_links'),
                            click: function(){
                              var $this = $(this),
                                aTrs = $('#list_' + div.find('div.id-holder').data('id')).dataTable().fnGetNodes(),
                                id_arr = [],
                                tb;

                              for ( var i=0 ; i<aTrs.length ; i++ ){
                                if ( $(aTrs[i]).hasClass('row_selected') ){
                                  id_arr.push( $(aTrs[i]).attr('id') );
                                }
                              }

                              if (select_one){
                                if (div.find('table.results').length < 1){
                                  core.message(core.tr('select_tb_to_continue'), 'error');
                                }
                                else if ( !id_arr){
                                  core.message(core.tr('select_row_to_continue'), 'error');
                                } else {
                                  tb = div.find('input.curr_tb').val();
                                  successFunction(tb, id_arr, $(this));
                                }
                              } else {
                                if (!id_arr){
                                  core.message(core.tr('select_tb_to_continue'), 'error');
                                }
                                else if ( !id_arr[0]){
                                  core.message(core.tr('select_row_to_continue'), 'error');
                                } else {
                                  tb = div.find('input.curr_tb').val();
                                  successFunction(tb, id_arr, $(this));
                                }
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
        },
        'json'
      );
    },

    /**
     * Deletes a userlink, shows system message and eventually runs callback function
     *
     * @param int linkId  id of the link to delete
     * @param function successFunction callback function to call if deletion was successfull
     * @returns {undefined}
     */
    delete_userlink: function(linkId, successFunction){
      core.getJSON('userlinks_ctrl', 'deleteUserLink', { "id": linkId }, false, function(data){
        if (data.status == 'success' && successFunction){
          successFunction(data);
        }
        core.message(data.text, data.status);
      });
    },

      /**
       * Shows usar links
       * @param object l_el jQuery object where to show links
       * @returns {undefined}
       */
    show_userlinks: function(l_el){
      var l_context = l_el.data('context');
      var l_tb = l_el.data('tb');
      var l_id = l_el.data('id');

      l_el.html('');

      core.getHTML('userlinks_ctrl', 'showUserLinks', {
        "tb": l_tb,
        "id": l_id,
        "context": l_context
      }, function(returned_html){
        l_el.html(returned_html);
          //READ
          $(l_el).find('span.userlink_read').on('click', function(){
            api.record.read($(this).data('tb'), [$(this).data('id')]);
          });


          //DELETE
          $(l_el).find('span.userlink_delete').click(function(){
            var $this = $(this),
              delId = $this.data('id');

            api.link.delete_userlink(delId, function(){
              $this.parent('li').remove();
            });
          });

          //RELOAD
          $(l_el).find('span.userlink_reload')
            .on('click',  () => {  api.link.show_userlinks(l_el);  });

          //ADD
          $(l_el).find('span.userlink_add')
            .on('click', () => {
              var thisid = $(this).data('id')
              var thistb = $(this).data('table');

              api.link.add_ui(function(tb, arr_id){
                core.getJSON('userlinks_ctrl', 'addUserLink', { thistb: thistb, thisid: thisid, tb: tb, id: arr_id }, false, function(data){
                  if (data.status === 'success'){
                    api.link.show_userlinks(l_el);
                  }
                  core.message(data.text, data.status);
                });
              });
            });
      });
    }
  },

  /**
   *
   * @param el object jQuery element to transform in button
   * @param url string url to use for file processing
   * @param opts  plain object dith options
   *     limitExtensions array aray of allowed file extension, default false
   *     limitSize int  filesize limits in bytes, default false
   *     limit2one boolean  if true only one file will be uploaded
   *     complete  function  function to run on complete, accepts as parameters id, name, responseJSON
   *     error    function  function to run on errors, accepts as parameters id, name, responseJSON
   *     button_text  string    string to use for button text
   * @returns {Boolean}
   */
  fileUpload: function(el, url, opts){

    opts = opts || {};

    if (!el || !url){
      return false;
    }
    var d = {
        request: {
          endpoint: url
        },
        autoUpload: true,
        text: {
          uploadButton: '<div><i class="glyphicon glyphicon-white glyphicon-upload"></i> ' + ( opts.button_text ? opts.button_text : core.tr('click_drag_to_upload') ) + '</div>',
          dragZone: core.tr('drop_to_upload')
        },
        template: `<div class="qq-uploader">
            <pre class="qq-upload-drop-area"><span>{dragZoneText}</span></pre>
            <div class="qq-upload-button btn btn-success" style="width: auto;">{uploadButtonText}</div>
              <span class="qq-drop-processing">
                <span>{dropProcessingText}</span>
                <span class="qq-drop-processing-spinner"></span>
              </span>
              <ul class="qq-upload-list" style="margin-top: 10px; text-align: center;"></ul>
            </div>`,
        classes: {
          success: 'alert alert-success',
          fail: 'alert alert-error'
        },
        validation: {
          allowedExtensions: (opts.limitExtensions ? opts.limitExtensions : ''),
          limitSize: (opts.limitSize ? opts.limitSize : '')
        }
    };

    d.multiple = !opts.limit2one;


    el.fineUploader(d)
      .on('complete', function(event, id, name, responseJSON){
        if (opts.complete){
          opts.complete(id, name, responseJSON);
        }
      })
      .on('error', function(event, id, name, reason){
        if (opts.error){
          opts.error(id, name, reason);
        }
      });
  }
};
