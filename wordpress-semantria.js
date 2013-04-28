
	function wp_semantria_ajax_handler( data ) {
		if ( data == 'finished' ) {
			window.location.href = window.location.href;
			return;
		}
		else {
			var offset = parseInt( data, 10);
			
			if ( isNaN( offset ) ) {
				jQuery( '#ltlCurrentPosition' ).text( 'ERROR!' );
				return;
			}
			
			jQuery( '#ltlCurrentPosition' ).text( offset );
			
			jQuery.post(
				ajaxurl,
				{
					action: 'wordpress_semantria_ingest_all',
					offset: offset
				},
				wp_semantria_ajax_handler,
				'text'
			);
		}
	}

	( function () {
		jQuery( document ).ready( function () { 
			jQuery( '#cmdPerformDataIngestion' ).click( function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				
				jQuery( this ).attr( 'disabled', 'disabled' );
				
				wp_semantria_ajax_handler( 0 );
			} );
		} );
	} )();