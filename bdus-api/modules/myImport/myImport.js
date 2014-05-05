/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 */

var myImport = {
		init: function(){
			core.open({
				obj: 'myImport_ctrl',
				method: 'button',
				title: core.tr('import'),
				loaded: function(){
					if ($('#modal a.import')[0]){
						
						var driver = $('#modal a.import').data('driver');
						
						api.fileUpload($('#modal a.import'), './controller.php?obj=myImport_ctrl&method=upload&driver=' + driver, {
							'complete': function(id, fileName, resp){
						
								if (driver == 'mysql') {
									
									core.open({
										html:$('<div />').attr('id', 'progress').progressbar().after($('<div />').attr('id', 'verbose')),
										title: core.tr('wait_dont_close'),
										
									}, 'modal');
									myImport.restoreSQL(escape(resp.uploadDir + fileName), 0, 0, 0);
									
								}else{
									core.message(resp.text, resp.status, true);
									$('#modal').modal('hide');
								}
							}
						});
					}
				}
			}, 'modal');
		},
		
		restoreSQL: function( filename, start, offset, totalqueries ) {
			
			core.getJSON('myImport_ctrl', 'importMysql', {filename: escape(filename), start: start, offset: offset, totalqueries: totalqueries}, false, function(data){
				
				if (data.status == 'loading'){
					//keep loading
					$('#progress').progressbar('value', data.bytes_percent);
					$('#verbose').html(
								'<h3>' + core.tr('import_loading') + '</h3>'
								+ core.tr('import_details_log', [data.lines_done, data.queries_done, data.bytes_done, data.bytes_percent])
								);
					
					myImport.restoreSQL(escape(filename), (data.lines_done + 1), data.bytes_done, data.queries_done);
					
				} else if (data.status == 'completed'){
					//completed
					$('#progress').progressbar('value', data.bytes_percent);
					$('#verbose').html(
							'<h3>' + core.tr('ok_import_complete') + '</h3>'
							+ core.tr('import_details_log', [data.lines_done, data.queries_done, data.bytes_done, data.bytes_percent])
							);
					
					
				} else if (data.status == 'error'){
					// completed with errors
					$('#progress').progressbar('value', 100);
					core.message(data.error, 'error');
				}
			});
		}
		
};




