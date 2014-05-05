/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2014
 * @license			See file LICENSE distributed with this code
*/

var utils = {

	/**
	 * clones jquery element {what} and appends to DOM following options
	 * 
	 * @param object what	jquery object element to clone from
	 * @param object opt	object with options:
	 * 		where (default:false) jquery object to clone in
	 * 		baseName (default: 'a') string, key of the array of the inputs / select / etc
	 * 		empty (default: true)	if true the value of the :input elements inside element is cleared
	 * 		changeName (default: true) if true the element name will be changed, otherwize it will not!
	 * 		cloneEvents (default: false) if true events will be cloned
	 * 		loaded (default: false) function to run on added dive, after clone is complete
	 * 		preserveEnhance (default: true) if false form elements will not be enhanced!
	 */
	
	myClone: function(what, opt){
		
		if (what.length == 0) return false;
		 
		if ( !opt ) {
			var opt = {};
		}

		//set settings
		var settings = $.extend({
			where:false,
			baseName:'a',
			empty:true,
			changeName: true,
			cloneEvents: false,
			loaded: false,
			preserveEnhance: true
		}, opt);
		
		// Private method
		var changeName = function(el){
			var name = el.attr('name');
			if(name){
				name = name.replace(new RegExp(settings.baseName, 'g'), settings.baseName + 's' +  rand + 'e');
				el.attr('name', name);
			}
		}

		// set rand value
		var rand = (new Date()).getTime();
		
		if (settings.preserveEnhance){
			what.find(':input.multiselect, select, :input.combobox').each(function(i, el){
				enhance.pimpEl(el, 'destroy');
			});
		}
		
		what.each(function(i, el){
			
			el = $(el);
			
			if (!el.hasClass('cloned') ){
				
				var cloned = el.clone(settings.cloneEvents);
				
				cloned.addClass('cloned');
				
				if ( !cloned.is(':visible') ) {
					cloned.show();
				}
				
				if (settings.empty || settings.changeName){
					
					if(cloned.is(':input')){
						
						if ( settings.empty ){
							cloned.val('');
						}
						
						if ( settings.changeName ){
							changeName(cloned);
						}
					} else {
						
						cloned.find(':input').each(function(i, cl_el){
							
							if ( settings.empty ){
								$(cl_el).val('');
							}
					
							if ( settings.changeName ){
								changeName($(cl_el));
							}
						});
					}
				}
				// create removeButton with click action
				var removeButton = $('<button />')
					.attr('type', 'button')
					.addClass('btn btn-default btn-sm')
					.css('margin', '10px 0')
					.html('<i class="glyphicon glyphicon-minus"></i>')
					.click(function(){
						$(this).parent('div.clonedContainer').remove();
						});
				
				// create cloned element container and put inside content
				var div = $('<div />')
					.attr('class', 'clonedContainer')
					.html(cloned)
					.append(removeButton);
		
				// place cloned content in DOM, depending on settings.where 
				if ( !settings.where ){
					el.after(div);
				}else{
					settings.where.append(div);
				}
				
				if (settings.preserveEnhance){
					cloned.find(':input.multiselect, select, :input.combobox').each(function(i, el){
						enhance.pimpEl(el);
					});
				}
				
				if (settings.loaded){
					settings.loaded(div);
				}
			}
		});
		if (settings.preserveEnhance){
			what.find(':input.multiselect, select, :input.combobox').each(function(i, el){
				enhance.pimpEl(el);
			});
		}
		

	}, // end of myClone
	
	/**
	 * 
	 * @param el
	 */
	myKeyboard: function(el){
		
		var customLayouts = {
				'default':	[
				          	 	'{meta1} {meta2} {meta3} {meta4} {meta5}',
								'',
								'\u00a0 \u00a1 \u00a2 \u00a3 \u00a4 \u00a5 \u00a6 \u00a7 \u00a8 \u00a9 \u00aa \u00ab \u00ac \u00ad \u00ae \u00af',
								'{sp:1} \u00b0 \u00b1 \u00b2 \u00b3 \u00b4 \u00b5 \u00b6 \u00b7 \u00b8 \u00b9 \u00ba \u00bb \u00bc \u00bd \u00be \u00bf',
								'\u00c0 \u00c1 \u00c2 \u00c3 \u00c4 \u00c5 \u00c6 \u00c7 \u00c8 \u00c9 \u00ca \u00cb \u00cc \u00cd \u00ce \u00cf',
								'{sp:1} \u00d0 \u00d1 \u00d2 \u00d3 \u00d4 \u00d5 \u00d6 \u00d7 \u00d8 \u00d9 \u00da \u00db \u00dc \u00dd \u00de \u00df',
								'\u00e0 \u00e1 \u00e2 \u00e3 \u00e4 \u00e5 \u00e6 \u00e7 \u00e8 \u00e9 \u00ea \u00eb \u00ec \u00ed \u00ee \u00ef',
								'{sp:1} \u00f0 \u00f1 \u00f2 \u00f3 \u00f4 \u00f5 \u00f6 \u00f7 \u00f8 \u00f9 \u00fa \u00fb \u00fc \u00fd \u00fe \u00ff',
								'\u0100 \u0101 \u0102 \u0103 \u0104 \u0105 \u0106 \u0107 \u0108 \u0109 \u010a \u010b \u010c \u010d \u010e \u010f',
								'{sp:1} \u0110 \u0111 \u0112 \u0113 \u0114 \u0115 \u0116 \u0117 \u0118 \u0119 \u011a \u011b \u011c \u011d \u011e \u011f',
								'\u0120 \u0121 \u0122 \u0123 \u0124 \u0125 \u0126 \u0127 \u0128 \u0129 \u012a \u012b \u012c \u012d \u012e \u012f',
								'{sp:1} \u0130 \u0131 \u0132 \u0133 \u0134 \u0135 \u0136 \u0137 \u0138 \u0139 \u013a \u013b \u013c \u013d \u013e \u013f',
								'\u0140 \u0141 \u0142 \u0143 \u0144 \u0145 \u0146 \u0147 \u0148 \u0149 \u014a \u014b \u014c \u014d \u014e \u014f',
								'{sp:1} \u0150 \u0151 \u0152 \u0153 \u0154 \u0155 \u0156 \u0157 \u0158 \u0159 \u015a \u015b \u015c \u015d \u015e \u015f',
								'\u0160 \u0161 \u0162 \u0163 \u0164 \u0165 \u0166 \u0167 \u0168 \u0169 \u016a \u016b \u016c \u016d \u016e \u016f',
								'{sp:1} \u0170 \u0171 \u0172 \u0173 \u0174 \u0175 \u0176 \u0177 \u0178 \u0179 \u017a \u017b \u017c \u017d \u017e \u017f',
								'',
								'{a} {c}'
								],
								
							// greek
							'meta1':	[
								'{meta1} {meta2} {meta3} {meta4} {meta5}',
								'',
								//a-m: maiuscole
								'\u0391 \u0392 \u0393 \u0394 \u0395 \u0396 \u0397 \u0398 \u0399 \u039A \u039B \u039C',
								//n-omega maiuscole
								'\u039D \u039E \u039F \u03A0 \u03A1 \u03A3 \u03A4 \u03A5 \u03A6 \u03A7 \u03A8 \u03A9',
								'',
								
								//a-m minuscole
								'\u03B1 \u03B2 \u03B3 \u03B4 \u03B5 \u03B6 \u03B7 \u03B8 \u03B9 \u03BA \u03BB \u03BC',
								//n-omema minuscole
								'\u03BD \u03BE \u03BF \u03C0 \u03C1 \u03C2 \u03C3 \u03C4 \u03C5 \u03C6 \u03C7 \u03C8 \u03C9',
								'',
								
								//dieresi + acuto + circomflesso
								'\u03AA \u03CA \u03AC \u03AD \u03AE \u03AF \u03CC \u03CD \u03CE \u03B0',
								'',
								'{a} {c}'
								],
							
							// coptic: 
							'meta2': [
								'{meta1} {meta2} {meta3} {meta4} {meta5}',
								'',
								'\u03AB \u03D4 \u03D0 \u03D1 \u03D2 \u03D3 \u03D5 \u03D6 \u03D7 \u03D8 \u03D9 \u03DA \u03DB \u03DC',
								'\u03DD \u03DE \u03DF \u03E0 \u03E1 \u03E2 \u03E3 \u03E4 \u03E5 \u03E6 \u03E7 \u03E8 \u03E9',
								'\u03EA \u03EB \u03EC \u03ED \u03EE \u03EF \u03F0 \u03F1 \u03F2 \u03F3 \u03F4 \u03F5',
								'\u03F6 \u03F7 \u03F8 \u03F9 \u03FA \u03FB \u03FC \u03FD \u03FE \u03FF',
								'',
								'{a} {c}'
								],
							
							//arabic
							'meta3': [
								'{meta1} {meta2} {meta3} {meta4} {meta5}',
								'',
								'\u0621 \u0622 \u0623 \u0624 \u0625 \u0626 \u0627 \u0628 \u0629 \u0630 \u0631 \u0632 \u0633 \u0634',
								'\u0635 \u0636 \u0637 \u0638 \u0639 \u0640 \u0641 \u0642 \u0643 \u0644 \u0645 \u0646 \u0647',
								'\u0648 \u0649 \u0650 \u0651 \u0652 \u0653 \u0654 \u0655 \u0656 \u0657 \u0658 \u0659 \u0660',
								'',
								'{a} {c}'
								],
							
							//phonetic
							'meta4':[
								'{meta1} {meta2} {meta3} {meta4} {meta5}',
								'',
								'\u0250 \u0251 \u0252 \u0253 \u0254 \u0255 \u0256 \u0257 \u0258 \u0259 \u0260 \u0260 \u0261 \u0262',
								'\u0263 \u0264 \u0265 \u0266 \u0267 \u0268 \u0269 \u0270 \u0270 \u0271 \u0272 \u0273 \u0274',
								'\u0275 \u0276 \u0277 \u0278 \u0279 \u0280 \u02A0 \u02A1 \u02A2 \u02A3 \u02A4 \u02A5 \u02A6 \u02A7',
								'\u02A8 \u02A9 \u02AA \u02AB \u02AC \u02AD \u02AE \u02AF \u025A \u026A \u027A \u028A \u029A',
								'\u025B \u026A \u027B \u028B \u029B \u025C \u026C \u027C \u028C \u029C \u025D \u026D \u027D \u028D',
								'\u029D \u025E \u026E \u027E \u028E \u029E \u025F \u026F \u027F \u028F \u029F',
								'',
								'{a} {c}'
								],
							
							//diacritics
							'meta5':[
								'{meta1} {meta2} {meta3} {meta4} {meta5}',
								'',
								'\u0300 \u0301 \u0302 \u0303 \u0304 \u0305 \u0306 \u0307 \u0308 \u0309 \u0310 \u0311 \u0312 \u0313 \u0314',
								'\u0315 \u0316 \u0317 \u0318 \u0319 \u0320 \u0321 \u0322 \u0323 \u0324 \u0325 \u0326 \u0327 \u0328',
								'\u0329 \u0330 \u0331 \u0332 \u0333 \u0334 \u0335 \u0336 \u0337 \u0338 \u0339 \u0340 \u0341 \u0342 \u0343',
								'\u0344 \u0345 \u0346 \u0347 \u0348 \u0349 \u0350 \u0351 \u0352 \u0353 \u0354 \u0355 \u0356 \u0357',
								'\u0358 \u0359 \u0360 \u0361 \u0362 \u0363 \u0364 \u0365 \u0366 \u0367 \u0368 \u0369 \u030A \u030B \u030C',
								'\u030D \u030E \u030F \u031A \u031B \u031C \u031D \u031E \u031F \u032A \u032B \u032C \u032D \u032E',
								'\u032F \u033A \u033B \u033C \u033D \u033E \u033F \u034A \u034B \u034C \u034D \u034E \u034F \u035A \u035B',
								'\u035C \u035D \u035E \u035F \u036A \u036B \u036C \u036D \u036E \u036F',
								'',
								'{a} {c}'
								]

							};
		
		
		$(el).keyboard({
			
			initialized: function(e, keyboard, el){
				keyboard.reveal();
				},
			
			accepted: function(e, keyboard, el){
				keyboard.destroy();
				$(el).attr('changed', 'auto');
				},
					
			canceled: function(e, keyboard, el){
				keyboard.destroy();
				},
				
			hidden: function(e, keyboard, el){
				keyboard.destroy();
				},
				
			layout: 'custom',
			
			display: {
				'meta1': 'Greek',
				'meta2': 'Coptic',
				'meta3': 'Arabic',
				'meta4': 'Phonetic',
				'meta5': 'Diacritics'
				},

			//http://unicode.coeurlumiere.com/
			//https://github.com/Mottie/Keyboard/wiki/Layout
			customLayout: customLayouts
				
			}); //end of keyboard
	} // end of myKeyboard
		
	
};