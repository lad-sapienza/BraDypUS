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
			} else if (typeof args == 'string'){
				args = [args];
			}
			
			if (typeof window[mod] == 'object' && !debugMode){
				window[mod]['init'].apply(null, args);
			} else {
				if (debugMode){
					var script = './modules/' + mod + '/' + mod + '.js';
				} else {
					var script = './modules/' + mod + '/' + mod + '.mini.js';
				}
				$.getScript(script, function(data){
					
					window[mod]['init'].apply(null, args);
					
					if (loaded){
						loaded();
					}
					
				}).fail(function(jqxhr, settings, exception) {
					console.log(exception);
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
			
			var URLstring = './controller.php?obj=' + obj + '&method=' + method;
    		
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
			if (type == 'modal'){
				layout.dialog.add(opt);
			} else {
				layout.tabs.add(opt);
			}
		},
		getHash: function(what){
			
			var hash = location.hash;
			
		    if(hash.length != 0){
		        // removes first 2 chars, ie. #/ and splits result in array
		    	hash = hash.substring(2).split('/');
		    	
		    	switch(what){
		    	
		    		case 'app':
		    			// #/app => app
		    			return hash[0];
		    			break;
		    			
		    		case 'mapId':
		    			// #/app/map/mapId
		    			if (hash[0] && hash[1] == 'map' && hash[2]){
		    				return hash[2];
		    			} else {
		    				return false;
		    			}
		    			break;
		    			
		    		case 'queryId':
		    			// #/app/query/queryId
		    			if (hash[0] && hash[1] == 'query' && hash[2]){
		    				return hash[2];
		    			} else {
		    				return false;
		    			}
		    			break;
		    			
		    		case 'chartId':
		    			// #/app/chart/chartId
		    			if (hash[0] && hash[1] == 'chart' && hash[2]){
		    				return hash[2];
		    			} else {
		    				return false;
		    			}
		    			break;
		    			
		    		case 'readId':
		    			// #/app/table/id
		    			if (hash[0] && hash[1] && hash[2]){
		    				return {app:hash[0], table: hash[1], id: hash[2], isIdField: hash[3]};
		    			}
		    			break;
		    			
	    			default:
	    				return hash;
	    				break;
		    	}
	        } else {
	        	return false;
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
