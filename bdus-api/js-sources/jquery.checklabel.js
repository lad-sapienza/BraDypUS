/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
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
			const label = $(this).find('label');
			const input = $(this).find('input');
			
			input.hide();
			
			label.on('click', function(){
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
