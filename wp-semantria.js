    
    var wpsemantria = {
        modal : {
            current : {
                post_id : '',
                semantria_id : ''
            },
            bind : function ( json ) {
                /**
                 * Get the basic article onto the screen.
                 */
                jQuery( 'div[rel="post"]', '#pnlSemantriaModal' ).html( Mustache.to_html( '<h3>{{title}}</h3>{{{body}}}', json.article ) ).fadeIn();
                
                /**
                 * Now we grab the bits of Semantria which are used in the WordPress plugin
                 * so that we can display them to the user.
                 */
                if ( json.entities ) {
                    wpsemantria.modal.bind_option( { item_title : 'Entities', item_type : 'entity', items : json.entities } );
                    
                    jQuery( 'div[rel="options"] input[name="entity[]"][type="checkbox"]', '#pnlSemantriaModal' ).change( function () {
                        if ( this.checked ) {
                            //jQuery( 'div[rel="post"] p', '#pnlSemantriaModal' ).highlight( jQuery( this ).val() );
                        }
                        else {
                            //jQuery( 'div[rel="post"] p', '#pnlSemantriaModal' ).highlight( jQuery( this ).val() );
                        }
                    } );
                }
                
                if ( json.themes ) {
                    wpsemantria.modal.bind_option( { item_title : 'Themes (Tags)', item_type : 'theme', items : json.themes } );
                }
                
                console.log( json );
            },
            bind_option : function ( data ) {
                jQuery( 'div[rel="options"]', '#pnlSemantriaModal' ).append( Mustache.to_html( '<h4>{{item_title}}</h4><ul>{{#items}}<li><label><input name="{{item_type}}[]" type="checkbox" value="{{title}}" />&nbsp;&nbsp;&nbsp;{{title}} {{#label}}({{label}}){{/label}}</label></li>{{/items}}</ul>', data ) );
            },
            hide : function () {
                jQuery( '#pnlSemantriaBackdrop' ).fadeOut();
                jQuery( '#pnlSemantriaModal' ).fadeOut();
            },
            reset : function () {
                jQuery( 'div[rel="post"]', '#pnlSemantriaModal' ).css( 'display', 'none' ).html( '' );
                jQuery( 'div[rel="options"]', '#pnlSemantriaModal' ).html( '' );
                
                jQuery( '#pnlLoading', '#pnlSemantriaModal' ).css( 'display', 'block' );
                
            },
            show : function () {
                jQuery( '#pnlSemantriaBackdrop' ).fadeIn();
                jQuery( '#pnlSemantriaModal' ).fadeIn();
                
                wpsemantria.modal.reset();
            }
        },
        get : function ( post_id, semantria_id ) {
            wpsemantria.modal.show();
            
            jQuery.post(
				ajaxurl,
				{
                    action: 'wp_semantria_get',
                    semantria_id : semantria_id,
                    post_id : post_id
                },
				wpsemantria.modal.bind,
				'json'
			);
        },
        update_status : function ( post_id, semantria_id, status ) {
            var $row = jQuery( '#row-' + post_id );
            var $post_title = jQuery( 'strong', $row );
            var original_text = $post_title.text();
            
            $post_title.text( 'Processing ...' );
            
            jQuery.post(
				ajaxurl,
				{
                    action: 'wp_semantria_update_status',
                    semantria_id : semantria_id,
                    post_id : post_id,
                    new_status : status
                },
				function ( status ) {
                    if ( status == 'done' ) {
                        setTimeout( function () { $row.fadeOut( 'slow' ); }, 250 );
                    }
                    else {
                        $post_title.text( original_text );
                    }
                },
				'text'
			);
        }
    };
    
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
					action: 'wp_semantria_ingest_all',
					offset: offset
				},
				wp_semantria_ajax_handler,
				'text'
			);
		}
	}

	( function ( $ ) {
		jQuery( document ).ready( function () { 
			jQuery( '#cmdPerformDataIngestion' ).click( function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				
				jQuery( this ).attr( 'disabled', 'disabled' );
				
				wp_semantria_ajax_handler( 0 );
			} );
            
            $( 'a.evaluate', '.wp-semantria-content > .wp-semantria-table' ).click( function ( e ) {
                e.preventDefault();
				e.stopPropagation();
				
                var $this = $( this );
                
                wpsemantria.get( $this.data( 'post-id'), $this.data( 'semantria-id' ) );
            } );
            
            $( 'a.update-status', '.wp-semantria-content > .wp-semantria-table' ).click( function ( e ) {
                e.preventDefault();
				e.stopPropagation();
				
                var $this = $( this );
                
                wpsemantria.update_status( $this.data( 'post-id'), $this.data( 'semantria-id' ), $this.data( 'next-status' ) );
            } );
            
            $( 'button.close-modal', '#pnlSemantriaModal' ).click( function ( e ) {
                e.preventDefault();
				e.stopPropagation();
                
                wpsemantria.modal.hide();
            } );
		} );
	} )( jQuery );
    