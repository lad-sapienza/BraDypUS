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
		 * @param {string} mod  module name to load
		 * @param {array} args array of arguments for modules init function
		 * @param {function|false} loaded 		the function to run once the module is loaded and init method has run.
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

		_get2url: function(get){
			if (typeof get === 'string'){
				return get;
			} else if($.isPlainObject(get))  {
				return $.param(get);
			} else if ($.isArray(get)){
				return 'param[]=' + get.join('&param[]=');
			}
			return false;
		},

		/**
		 * Performs an Ajax call and gets JSON from object::method
		 * @param {string} obj object to call (with _ctrl part)
		 * @param {string} method method to call
		 * @param {string|array|object} get get data
		 * @param {string|array|object} post post data
		 * @param {function|false} loaded callback function
		 * @returns {undefined}
		 */
		getHTML: function(obj,method,get,post,loaded){

			var URLstring = './?obj=' + obj + '&method=' + method
				+ ( get ? '&' + this._get2url(get) : '');
			
			if (!post){
				$.get(URLstring, loaded);
			} else {
				$.post(URLstring, post, loaded);
			}
		},

		/**
		 * Performs an Ajax call and gets JSON from object::method
		 * @param {string} obj object to call (with _ctrl part)
		 * @param {string} method method to call
		 * @param {string|array|object} get get data
		 * @param {string|array|object} post post data
		 * @param {function|false} loaded callback function
		 * @returns {undefined}
		 */
		getJSON: function(obj,method,get,post,loaded){
    		var URLstring = './?obj=' + obj + '&method=' + method
				+ ( get ? '&' + this._get2url(get) : '');

			if (!post){
				$.get(URLstring, loaded, 'json');
			} else {
				$.post(URLstring, post, loaded, 'json');
			}
		},

		/**
		 * Utility wrapper of getJSON
		 * Automatically runs core.message on response
		 * @param {string} obj 		Controller object to call
		 * @param {string} method 	Method of controller object to call
		 * @param {string|object|false} get url string of key=value parameters of objectwith key:values
		 * @param {string|array|object|false} post post data 
		 */
		runAndRespond(obj, method, get, post) {
			core.getJSON ( obj, method, get, post, data => {
				core.message(data.text, data.status);
			});
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
		/**
		 * Returns translates string, if translation is available
		 * @param string string String to be translated
		 * @param array|false args Array of argument to use for vsprintf
		 */
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
		},


		/**
		 * Displays system message
		 * @param string text		Message text
		 * @param string type		Message type: error|sucess|info, default: info
		 * @param boolean sticky if true message will be sticky
		 */
		message: function(text, type, sticky) {
			if (!type){
				type = 'info';
			}
			if (type === 'info' || type === 'success' || type === 'warning' || type === 'error'){
				iziToast[type]({
					message: text
				});
			} else {
				alert(text);
			}
		}
};
