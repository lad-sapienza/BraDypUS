/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 */

var myHistory = {
		init: function(){
			$.get('./?obj=myHistory_ctrl&method=show_all', function(data){
				if(data && data !== '<pre></pre>'){
					core.open({
						html: data,
						title: core.tr('history')
					});
				} else {
					core.message(core.tr('history_is_empty'));
				}
			});

		}
};
