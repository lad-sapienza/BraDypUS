/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS 2007-2012
 * @license			All rights reserved
 * @since			Apr 16, 2012
 * 
 * use:
 * 	html
 * 	<span class="checklabel">
 * 		<input type="checkbox" />
 * 		<label>clickme<label>
 * 	</span>
 * 
 * javascript
 * <script>
 * 		$('span.checklabel').checklabel();
 * </script>
 */

(function( $ ){

	$.fn.checklabel = function() {
		
		this.each(function(){
			var label = $(this).find('label');
			var input = $(this).find('input');
			
			input.hide();
			
			label.click(function(){
				if ( input.is(':checked') ) {
					input.attr('checked', false);
					label.removeClass('selected');
					
				} else {
					input.attr('checked', true);
					label.addClass('selected');
				}
			});
		});
	};
})( jQuery );
