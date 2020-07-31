/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 * @since			15/apr/2012
 */


var core = {

		/**
		 * Checks if module is present. If not the module is loaded.
		 * Runs the init method of module using args as function arguments
		 * If loaded() function is present it will be run after init function is called. [added in v. 3.0.3]
		 *
		 * @param mod string module name to load
		 * @param args array array of arguments for modules init function
		 * @param loaded function|false		the function to run once the module is loaded and init method has run.
		 */
		runMod: function(mod, args, loaded){

			if (!args){
				args = [];
			} else if (typeof args === 'string'){
				args = [args];
			}

			if (typeof window[mod] === 'object' && !debugMode){
				window[mod].init.apply(null, args);
			} else {
				var script;
				if (debugMode){
					script = './modules/' + mod + '/' + mod + '.js';
				} else {
					script = './modules/' + mod + '/' + mod + '.mini.js';
				}

				$.ajax({
				  url: script,
				  dataType: "script",
					suppressErrors: true,
				  success: function(data){
						window[mod].init.apply(null, args);
						if (loaded){
							loaded();
						}
					},
					error: function(jqxhr, settings, exception) {
						core.open({
							obj: mod,
							method: 'read',
							title: core.tr(mod)
						});
					}
				});
			}
		},

		/**
		 * returns img tag with loading gif
		 */
		loading : '<img src="./img/loader.gif"  alt="loading..." />',

		/**
		 * Performs an Ajax call and gets JSON from object::method
		 * @param string obj object to call (with _ctrl part)
		 * @param string method method to call
		 * @param string|array|object get get data
		 * @param string|array|object post post data
		 * @param function|false loaded callback function
		 * @returns {undefined}
		 */
		getJSON: function(obj,method,get,post,loaded){

			var URLstring = './?obj=' + obj + '&method=' + method;

    		if (get){
	        	if (typeof get === 'string'){
					URLstring += '&' + get;
				} else if($.isPlainObject(get))  {
					URLstring += '&' + $.param(get);
				} else if ($.isArray(get)){
					URLstring += '&param[]=' + get.join('&param[]=');
				}
    		}

			if (!post){
				$.get(URLstring, loaded, 'json');
			} else {
				$.post(URLstring, post, loaded, 'json');
			}
		},

		/**
		 *
		 * @param opts
		 *
		 * opt.obj		*	string
		 * opt.method	*	string
		 * opt.param	?	array|string
		 * opt.title	?	string
		 * opt.post		?	array
		 * opt.loaded	?	function
		 * opt.unique	?	boolean		[tabs only]
 		 * opt.opts		?	object		[dialog only]
		 */
		open: function(opt, type){
			if (type === 'modal'){
				layout.dialog.add(opt);
			} else {
				layout.tabs.add(opt);
			}
		},
		tr: function (string, args) {

			if (!lang[string]) {

				return string;

			} else {
				if (!args) {

					return lang[string];

				} else {

					return vsprintf(lang[string], args);

				}
			}
			return lang[string] ? lang[string] : string;
		},


		/**
		 * Displays system message
		 * @param string text		Message text
		 * @param string type		Message type: error|sucess|info, default: info
		 * @param boolean sticky if true message will be sticky
		 */
		message: function(text, type, sticky) {

			$.pnotify({
		        //title: title ?  title : false,
				history: false,
		        text: text,
		        type: type ? type : false,
		        hide: sticky ? false : true,
        		styling: "bootstrap"
		    });
		}
};
